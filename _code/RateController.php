<?php

namespace frontend\controllers;

use common\components\CompanyController;
use common\components\CompanyFilter;
use common\models\Ad;
use common\models\AdLaunchSearch;
use common\models\AdStatSearch;
use common\models\Rate;
use common\models\RateEmployee;
use common\models\RatePlan;
use common\models\RateSearch;
use common\models\RateValue;
use common\models\RateValueSearch;
use DateInterval;
use DatePeriod;
use DateTime;
use modules\users\models\User;
use rbac\Access;
use Yii;
use yii\base\Model;
use yii\data\ArrayDataProvider;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\validators\DateValidator;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Работа с показателями
 */
class RateController extends CompanyController
{
  /**
   * @inheritdoc
   * @return array
   */
  public function behaviors() {
    return [
      'verbs' => [
        'class' => VerbFilter::className(),
        'actions' => [
          'delete' => ['post'],
        ],
      ],
      'access' => [
        'class' => AccessControl::className(),
        'rules' => [
          [
            'actions' => ['index'],
            'allow' => true,
            'roles' => [Access::DATA_INDEX],
          ],
          [
            'actions' => ['my'],
            'allow' => true,
            'roles' => [Access::STATS_FILL_EMPLOYEE],
          ],
          [
            'actions' => ['create'],
            'allow' => true,
            'roles' => [Access::DATA_CREATE],
          ],
          [
            'allow' => true,
            'roles' => ['@'],
          ],
        ],
      ],
      'company' => [
        'class' => CompanyFilter::className(),
      ],
    ];

  }

  /**
   *  Список
   *
   * @return mixed
   */
  public function actionIndex() {
    $searchModel = new RateSearch();
    $searchModel->scenario = RateSearch::SCENARIO_SEARCH_BY_EMPLOYEE;
    $searchModel->companyId = $this->company->id;
    $searchModel->isDeleted = 0;
    $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

    return $this->render('index', [
      'searchModel' => $searchModel,
      'dataProvider' => $dataProvider,
      'company' => $this->company,
    ]);
  }

  /**
   *
   *
   */
  public function getMonthsLabels() {
    return [
      '0' => 'Не месяц',
      '1' => 'Январь',
      '2' => 'Февраль',
      '3' => 'Март',
      '4' => 'Апрель',
      '5' => 'Май',
      '6' => 'Июнь',
      '7' => 'Июль',
      '8' => 'Август',
      '9' => 'Сентябрь',
      '10' => 'Октябрь',
      '11' => 'Ноябрь',
      '12' => 'Декабрь',
    ];
  }

  public function getPeriodNumbers($dateStart, $dateEnd, $period) {
    $dStart = $dateStart;
    $dEnd = $dateEnd;
    //if (is_string($dStart)) $dStart = strtotime($dStart);
    //if (is_string($dEnd)) $dEnd = strtotime($dEnd);

    $periodNumbers = [];
    if ($period == Rate::WEEKLY_PLAN) {
      $thisWeekStart = date("d.m.Y", strtotime("last monday", strtotime("tomorrow", $dStart)));
      $begin = new DateTime($thisWeekStart);
      $end = new DateTime(date("d.m.Y", $dEnd) . " +1 day");
      $interval = DateInterval::createFromDateString('1 week');
      $datePeriod = new DatePeriod($begin, $interval, $end);
      /** @var DateTime $dt */
      foreach ($datePeriod as $dt) {
        $periodNumbers[(int)$dt->format("Y")][(int)$dt->format("W")]['dateStart'] = $dt->format("d.m.Y");
        $periodNumbers[(int)$dt->format("Y")][(int)$dt->format("W")]['dateEnd'] = date("d.m.Y", strtotime("+ 6 days", $dt->getTimestamp()));
        $periodNumbers[(int)$dt->format("Y")][(int)$dt->format("W")]['label'] = $dt->format("d.m.Y") . " - " . date("d.m.Y", strtotime("+ 6 days", $dt->getTimestamp()));
      }
    } elseif ($period == Rate::MONTHLY_PLAN) {
      $thisMonthStart = date("d.m.Y", strtotime("first day of this month", $dStart));
      $begin = new DateTime($thisMonthStart);
      $end = new DateTime(date("d.m.Y", $dEnd) . " +1 day");
      $interval = DateInterval::createFromDateString('1 month');
      $datePeriod = new DatePeriod($begin, $interval, $end);
      /** @var DateTime $dt */
      foreach ($datePeriod as $dt) {
        $periodNumbers[(int)$dt->format("Y")][(int)$dt->format("m")]['dateStart'] = $dt->format("d.m.Y");
        $periodNumbers[(int)$dt->format("Y")][(int)$dt->format("m")]['dateEnd'] = date("d.m.Y", strtotime("last day of this month", $dt->getTimestamp()));
        $periodNumbers[(int)$dt->format("Y")][(int)$dt->format("m")]['label'] = $this->getMonthsLabels()[(int)$dt->format("m")] . " " . $dt->format("Y");
      }
    }
    return $periodNumbers;
  }

  /**
   * Просмотр
   *
   * @param integer $id
   * @return mixed
   */
  public function actionView($id, $dateStart = null) {
    $model = $this->findModel($id, Access::DATA_VIEW);

    $employees = [];
    $periodNumbers = [];
    $planModels = [];
    $disablePeriodFields = [];

    $model->scenario = Rate::SCENARIO_ADD_PLAN;
    if ($model->load(Yii::$app->request->post()) && $model->save()) {
      Yii::$app->session->addFlash('success', 'Показатель изменен');
      return $this->redirect(['view', 'id' => $model->id, 'companyId' => $this->company->id]);
    }
    if ($model->planStart === null) {
      $model->planStart = date('d.m.Y', strtotime('now'));
      $model->planPeriod = 1;
    } else {
      $model->scenario = Rate::SCENARIO_DEFAULT;

      /** @var RateEmployee $links */
      foreach ($model->getActiveEmployeeLinks() as $links) {
        $employees[$links->employeeId] = $links->employee->fullname;
      }

      if ($dateStart === null) $dateStart = max(strtotime("now"), $model->planStart);
      if ($model->planPeriod == Rate::WEEKLY_PLAN) {
        $dateEnd = strtotime("next week", $dateStart);
      } elseif ($model->planPeriod == Rate::MONTHLY_PLAN) {
        $dateEnd = strtotime("next month", $dateStart);
      } else {
        $dateEnd = strtotime("now");
      }

      $periodNumbers = $this->getPeriodNumbers($dateStart, $dateEnd, $model->planPeriod);

      foreach ($periodNumbers as $year => $numbers) {
        $plans[$year] = RatePlan::find()
          ->andWhere(['employeeId' => array_keys($employees)])
          ->andWhere(['rateId' => $model->id])
          ->andWhere(['periodNumber' => array_keys($numbers)])
          ->andWhere(['periodYear' => $year])
          ->indexBy(function ($model) {
            /** @var RatePlan $model */
            return $model->periodNumber . '-' . $model->employeeId;
          })->all();
      }

      /** @var RatePlan[] $planModels */
      foreach ($periodNumbers as $year => $numbers) {
        foreach ($numbers as $number => $period) {
          foreach ($employees as $employeeId => $fullname) {
            $planModels[$number . '-' . $employeeId] = isset($plans[$year][$number . '-' . $employeeId])
              ? $plans[$year][$number . '-' . $employeeId]
              : new RatePlan([
                'employeeId' => $employeeId,
                'rateId' => $model->id,
                'periodNumber' => $number,
                'periodYear' => $year,
              ]);
            $disablePeriodFields[$year][$number][$employeeId] = strtotime($period["dateEnd"]) < $model->planStart;
          }
        }
      }

      if (Model::loadMultiple($planModels, Yii::$app->request->post())) {
        if (Model::validateMultiple($planModels)) {
          foreach ($periodNumbers as $year => $numbers) {
            foreach ($numbers as $number => $period) {
              foreach ($employees as $employeeId => $fullname) {
                $planModels[$number . '-' . $employeeId]->save(false);
              }
            }
          }
        }
      }
    }

    return $this->render('view', [
      'model' => $model,
      'company' => $this->company,
      'periodNumbers' => $periodNumbers,
      'disablePeriodFields' => $disablePeriodFields,
      'employees' => $employees,
      'planModels' => $planModels,
    ]);
  }

  /**
   * Добавление
   *
   * @return mixed
   */
  public function actionCreate() {
    $model = new Rate();
    $model->companyId = $this->company->id;
    $model->populateRelation('company', $this->company);
    $model->userCreatedId = Yii::$app->user->identity->id;

    if ($model->load(Yii::$app->request->post()) && $model->save()) {
      Yii::$app->session->addFlash('success', 'Показатель добавлен');
      return $this->redirect(['index', 'companyId' => $this->company->id]);
    } else {
      return $this->render('create', [
        'model' => $model,
        'company' => $this->company,
      ]);
    }
  }

  /**
   * Редактирование
   *
   * @param integer $id
   * @return mixed
   */
  public function actionUpdate($id) {
    $model = $this->findModel($id, Access::DATA_UPDATE);

    if ($model->load(Yii::$app->request->post()) && $model->save()) {
      Yii::$app->session->addFlash('success', 'Показатель изменен');
      return $this->redirect(['view', 'id' => $model->id, 'companyId' => $this->company->id]);
    } else {
      return $this->render('update', [
        'model' => $model,
        'company' => $this->company,
      ]);
    }
  }

  /**
   * Удаление
   *
   * @param integer $id
   * @return mixed
   */
  public function actionDelete($id) {
    $model = $this->findModel($id, Access::DATA_UPDATE);
    if ($model->delete()) {
      Yii::$app->session->addFlash('success', 'Показатель удален');
    }

    return $this->redirect(['index', 'companyId' => $this->company->id]);
  }

  /**
   * Finds the Communication model based on its primary key value.
   * If the model is not found, a 404 HTTP exception will be thrown.
   *
   * @param integer $id
   * @param null $checkAction
   * @return Rate the loaded model
   * @throws ForbiddenHttpException
   * @throws NotFoundHttpException
   */
  protected function findModel($id, $checkAction = null) {
    $model = Rate::findOne($id);

    if ($model === null) {
      throw new NotFoundHttpException('The requested page does not exist.');
    }

    if ($checkAction) {
      $user = Yii::$app->user;
      if (!$user->can($checkAction, ['object' => $model])) {
        if ($user->getIsGuest()) {
          $user->loginRequired();
        } else {
          throw new ForbiddenHttpException('У вас нет доступа к запрошенной странице');
        }
      }
    }

    return $model;
  }

  /*
   * Функция возвращает номер периода по текущей дате.
   * Либо номер недели либо номер месяца.
   *
   * @param integer|string $dateStart
   * @param integer|string $dateEnd
   * @return array|bool
   */
  protected function getDatePeriodIndexes($dateStart, $dateEnd) {
    $_result = [];
    $_start = $dateStart;
    $_end = $dateEnd;

    if (is_string($dateStart)) $_start = strtotime($dateStart);
    if (is_string($dateEnd)) $_end = strtotime($dateEnd);

    $_result[] = (int)date('W', $_start); //номер недели
    $_result[] = (int)date('m', $_start); //номер месяца от даты начала
    $_result[] = (int)date('m', $_end); //номер месяца от даты окончания

    return $_result;
  }

  /**
   *  Список своих показателей сотрудника
   *
   * @param null $dateStart
   * @return mixed
   */
  public function actionMy($dateStart = null) {
    if ($dateStart) {
      $validator = new DateValidator();
      $validator->format = 'php:d.m.Y';
      if (!$validator->validate($dateStart))
        $dateStart = null;
      else
        $dateStart = date('d.m.Y', strtotime('last monday', strtotime('tomorrow', strtotime($dateStart)))); //strtotime issue https://bugs.php.net/bug.php?id=63740 see [2013-01-23 16:04 UTC] googleguy@php.net
    }
    if (!$dateStart) $dateStart = date('d.m.Y', strtotime('last monday', strtotime('tomorrow')));

    $daysCount = 6;
    $dateEnd = date('d.m.Y', strtotime($dateStart . "+ $daysCount days"));

    /** @var RateEmployee[] $rateLinks */
    $rateLinks = Yii::$app->user->identity->getRateLinks()->andWhere(['active' => 1])->all();

    $data = [];
    $rateIds = [];
    foreach ($rateLinks as $link) {
      if (!isset($link->rate->planStart)) {
        $data[$link->rateId]['rate'] = $link->rate;
        $rateIds[] = $link->rateId;
      }
    }

    $oldValues = RateValue::find()->andWhere([
      'rateId' => $rateIds,
      'employeeId' => Yii::$app->user->id,
    ])->andWhere([
      'between',
      'date',
      date('Y-m-d', strtotime($dateStart)),
      date('Y-m-d', strtotime($dateEnd))
    ])->indexBy(function ($model) {
      /** @var RateValue $model */
      return $model->rateId . '-' . date('d.m.Y', strtotime($model->date));
    })->all();

    foreach ($rateIds as $rateId) {
      for ($i = $daysCount; $i >= 0; $i--) {
        $date = date('d.m.Y', strtotime($dateEnd . "- $i days"));
        $data[$rateId][$date] = isset($oldValues[$rateId . '-' . $date])
          ? $oldValues[$rateId . '-' . $date]
          : new RateValue([
            'rateId' => $rateId,
            'date' => $date,
          ]);
      }
    }

    $dataProvider = new ArrayDataProvider([
      'allModels' => $data,
      'pagination' => [
        'pageSize' => count($rateIds),
      ]
    ]);

    return $this->render('my', [
      'dataProvider' => $dataProvider,
      'company' => $this->company,
      'dateStart' => $dateStart,
      'dateEnd' => $dateEnd,
      'daysCount' => $daysCount,
    ]);
  }

  /**
   *  Список планов сотрудника
   *
   * @param null $dateStart
   * @return mixed
   */
  public function actionMyPlans($dateStart = null) {
    if ($dateStart) {
      $validator = new DateValidator();
      $validator->format = 'php:d.m.Y';
      if (!$validator->validate($dateStart))
        $dateStart = null;
      else
        $dateStart = date('d.m.Y', strtotime('last monday', strtotime('tomorrow', strtotime($dateStart)))); //strtotime issue https://bugs.php.net/bug.php?id=63740 see [2013-01-23 16:04 UTC] googleguy@php.net
    }
    if (!$dateStart) $dateStart = date('d.m.Y', strtotime('last monday', strtotime('tomorrow')));

    $daysCount = 6;
    $dateEnd = date('d.m.Y', strtotime($dateStart . "+ $daysCount days"));

    /** @var RateEmployee[] $rateLinks */
    $rateLinks = Yii::$app->user->identity->getRateLinks()->andWhere(['active' => 1])->all();

    $rateIds = [];
    $rateTitles = [];

    foreach ($rateLinks as $link) {
      if (isset($link->rate->planStart)) {
        $rateIds[] = $link->rateId;
        $rateTitles[$link->rateId] = $link->rate->title;
      }
    }

    $oldValues = RateValue::find()->andWhere([
      'rateId' => $rateIds,
      'employeeId' => Yii::$app->user->id,
    ])->andWhere([
      'between',
      'date',
      date('Y-m-d', strtotime($dateStart)),
      date('Y-m-d', strtotime($dateEnd))
    ])->indexBy(function ($model) {
      /** @var RateValue $model */
      return $model->rateId . '-' . date('d.m.Y', strtotime($model->date));
    })->all();

    $allRatePlans = RatePlan::find()->andWhere([
      'rateId' => $rateIds,
      'employeeId' => Yii::$app->user->id,
      'periodNumber' => $this->getDatePeriodIndexes($dateStart, $dateEnd),
      'checked' => 1,
    ])->all();

    $allPlansValues = [];
    /** @var RatePlan $plan */
    foreach ($allRatePlans as $plan) {
      if ($plan->rate->planPeriod == Rate::MONTHLY_PLAN) {
        $dStart = strtotime($plan->periodYear . "-" . $plan->periodNumber);
        $dEnd = strtotime('last day of this month', $dStart);
        if ($dEnd > strtotime($dateEnd)) $dEnd = strtotime($dateEnd);
      } else {
        $dStart = strtotime($dateStart);
        $dEnd = strtotime($dateEnd);
      }

      $planPeriodSum = RateValue::find()->andWhere([
        'rateId' => $plan->rateId,
        'employeeId' => Yii::$app->user->id,
      ])->andWhere(['between', 'date', date('Y-m-d', $dStart), date('Y-m-d', $dEnd)])
        ->sum('value');

      $allPlansValues[$plan->rateId][date('d.m.Y', $dEnd)] = ($planPeriodSum ?: 0) . "/" . $plan->value;
    }

    $data = [];
    /** @var RatePlan $plan */
    foreach ($allPlansValues as $rateId => $planDates) {
      $data[$rateId]['rateTitle'] = $rateTitles[$rateId];
      for ($i = $daysCount; $i >= 0; $i--) {

        $date = date('d.m.Y', strtotime($dateEnd . "- $i days"));
        if ($date === date('d.m.Y', strtotime('now'))) {
          $data[$rateId][$date]['rateValue'] = isset($oldValues[$rateId . '-' . $date])
            ? $oldValues[$rateId . '-' . $date]
            : new RateValue([
              'rateId' => $rateId,
              'date' => $date,
            ]);
        } else {
          $data[$rateId][$date]['rateValue'] = isset($oldValues[$rateId . '-' . $date])
            ? $oldValues[$rateId . '-' . $date]->value
            : null;
        }
        if (isset($planDates[$date])) $data[$rateId][$date]['planValue'] = $planDates[$date];
      }
    }

    $dataProvider = new ArrayDataProvider([
      'allModels' => $data,
      'pagination' => [
        'pageSize' => count($rateIds),
      ]
    ]);

    return $this->render('myPlans', [
      'dataProvider' => $dataProvider,
      'company' => $this->company,
      'dateStart' => $dateStart,
      'dateEnd' => $dateEnd,
      'daysCount' => $daysCount,
    ]);
  }

  /**
   *  Статистика показателей с группировкой по сотрудникам
   *
   * @return mixed
   */
  public function actionEmployeesStats() {

    $searchModel = new RateValueSearch();
    $searchModel->scenario = RateValueSearch::SCENARIO_SEARCH_BY_EMPLOYEE;
    $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

    return $this->render('employeeStats', [
      'searchModel' => $searchModel,
      'dataProvider' => $dataProvider,
      'company' => $this->company,
    ]);
  }

  /**
   *   Статистика показателей с группировкой по показателям
   *
   * @return mixed
   */
  public function actionRatesStats() {

    $searchModel = new RateValueSearch();
    $searchModel->scenario = RateValueSearch::SCENARIO_SEARCH_BY_RATE;
    $params = Yii::$app->request->queryParams;
    $dataProvider = $searchModel->search($params);

    $chartModeData['selection'] = isset($params['chartMode']) ? $params['chartMode'] : 0;
    $charts = [];

    if ($dataProvider->totalCount) {
      if ($chartModeData['selection'] == 0) { //выбрано "Общий график"

        // получаем сохранненые данные
        $data = [];
        foreach ($dataProvider->models as $value) {
          /** @var \common\models\RateValue $value */
          $data[$value->employeeId][$value->date] = $value->value ?: 0;
        }

        // получаем  сотрудников прикрепленных к показателю
        /** @var User[] $employees */
        $employees = [];
        /** @var \common\models\RateEmployee[] $links */
        if (!$searchModel->employeeId) {
          $links = $searchModel->rate->getEmployeeLinks()->andWhere(['active' => 1])->all();
        } else {
          $links = $searchModel->rate->getEmployeeLinks()->andWhere([
            'employeeId' => $searchModel->employeeId,
            'active' => 1
          ])
            ->all();
        }
        foreach ($links as $link) {
          $employees[$link->employeeId] = $link->employee;
        }

        // заполняем данные для графика
        $begin = new DateTime($searchModel->dateFrom);
        $end = new DateTime($searchModel->dateTo . " + 1 day");
        $interval = DateInterval::createFromDateString('1 day');
        $period = new DatePeriod($begin, $interval, $end);

        $periodYears = [];
        /** @var DateTime $dt */
        foreach ($period as $dt) {
          $periodYears[] = $dt->format("Y");
        }
        $periodYears = array_unique($periodYears);

        /** @var Rate $rate */
        $rate = Rate::findOne($searchModel->rateId);

        $planSum = null;
        if ($rate->planStart !== null) {
          $periodNumbers = [];
          foreach ($periodYears as $year) {
            if ($rate->planPeriod == Rate::WEEKLY_PLAN) {
              foreach ($period as $dt) {
                $periodNumbers[$year][] = $dt->format("W");
              }
            } elseif ($rate->planPeriod == Rate::MONTHLY_PLAN) {
              foreach ($period as $dt) {
                $periodNumbers[$year][] = $dt->format("m");
              }
            }
          }
          foreach ($periodYears as $year) {
            $periodNumbers[$year] = array_unique($periodNumbers[$year]);
          }

          $ratePlans = [];
          foreach ($employees as $employeeId => $employee) {
            $planSum[$employeeId] = 0;
            foreach ($periodYears as $year) {
              /** @var Rate $rate */
              $ratePlans[$employeeId][$year] = $rate->getPlanLinks()->andWhere([
                'employeeId' => $employeeId,
                'periodNumber' => $periodNumbers[$year],
                'periodYear' => $year,
                'checked' => 1,
              ])->indexBy('periodNumber')->all();

              foreach ($ratePlans[$employeeId][$year] as $plan) {
                $planSum[$employeeId] += $plan->value;
              }
            }
          }
        }

        $i = 0;
        foreach ($employees as $employeeId => $employee) {
          $valueSum = 0;
          /** @var DateTime $dt */
          foreach ($period as $dt) {
            $date = $dt->format("d.m.Y");
            $value = isset($data[$employeeId][$date]) ? $data[$employeeId][$date] : 0;
            $charts[$i]['data'][] = [$dt->format("d.m"), $value];
            $valueSum += $value;
          }

          $charts[$i]['lines'] = ['show' => true, 'fill' => true];
          $charts[$i]['points'] = ['show' => true];
          $charts[$i]['label'] = $employee->fullname . ": $valueSum" . ($planSum !== null ? "/$planSum[$employeeId]" : "");
          $i++;
        }
      } elseif ($chartModeData['selection'] == 1) {  //выбрано "выполнение плана"

        /** @var Rate $rate */
        $rate = Rate::findOne($searchModel->rateId);

        if ($rate->planStart !== null) {
          if (!$searchModel->employeeId) {
            $employeeLinks = $rate->getEmployeeLinks()
              ->andWhere(['active' => 1])->all();
          } else {
            $employeeLinks = $rate->getEmployeeLinks()
              ->andWhere(['employeeId' => $searchModel->employeeId, 'active' => 1])->all();
          }

          $employees = [];
          /** @var RateEmployee $links */
          foreach ($employeeLinks as $links) {
            $employees[$links->employeeId] = $links->employee->fullname;
          }

          $periodNumbers = $this->getPeriodNumbers($searchModel->dateFrom, $searchModel->dateTo, $rate->planPeriod);

          foreach ($periodNumbers as $year => $numbers) {
            /** @var array $period */
            foreach ($numbers as $number => $period) {
              $dStart = strtotime($period['dateStart']);
              $dEnd = strtotime($period['dateEnd']);

              $plans[$year] = RatePlan::find()
                ->andWhere([
                  'employeeId' => array_keys($employees),
                  'rateId' => $rate->id,
                  'periodNumber' => array_keys($numbers),
                  'periodYear' => $year,
                  'checked' => 1,
                ])
                ->indexBy(function ($model) {
                  /** @var RatePlan $model */
                  return $model->periodNumber . '-' . $model->employeeId;
                })->all();

              foreach ($employees as $employeeId => $fullname) {
                $rateValueSum[$year][$number][$employeeId] = RateValue::find()->andWhere([
                  'rateId' => $rate->id,
                  'employeeId' => $employeeId,
                ])->andWhere(['between', 'date', date('Y-m-d', $dStart), date('Y-m-d', $dEnd)])
                  ->sum('value');
              }
            }
          }

          $i = 1;
          $commonPlanSum = 0;
          /** @var RatePlan[] $planModels */
          foreach ($employees as $employeeId => $fullname) {
            $planSum = 0;
            $valueSum = 0;
            $j = 0;
            foreach ($periodNumbers as $year => $numbers) {
              foreach ($numbers as $number => $period) {
                $dStart = strtotime($period['dateStart']);
                $dEnd = strtotime($period['dateEnd']);

                $sum = isset($rateValueSum[$year][$number][$employeeId]) ? $rateValueSum[$year][$number][$employeeId] : null; //null чтобы не отображался график если нет значений
                $valueSum += $sum;

                $plan = isset($plans[$year]["$number-$employeeId"]) ? $plans[$year]["$number-$employeeId"]->value : null;
                $planSum += $plan;
                $commonPlanSum += $plan;

                $charts[$i]['data'][] = [date('d.M', $dStart) . "-" . date('d.M', $dEnd), $sum];
                if (($plan === null) && ($sum !== null)) $plan = 0; //если есть значения показателей, но значение checked плана 0
                if ($searchModel->employeeId) {
                  $charts[0]['data'][] = [date('d.M', $dStart) . "-" . date('d.M', $dEnd), $plan];
                } else {
                  $commonPlan = isset($charts[0]['data'][$j][1]) ? (int)$charts[0]['data'][$j][1] : null;
                  if (($plan !== null) || ($commonPlan !== null)) $commonPlan += $plan;
                  $charts[0]['data'][$j] = [date('d.M', $dStart) . "-" . date('d.M', $dEnd), $commonPlan];
                  $j++;
                }
              }
              $charts[$i]['label'] = "$fullname: $valueSum/$planSum";
              $charts[$i]['bars'] = ['show' => true, 'align' => 'center',];  //'barWidth' => 0.6,
              $charts[$i]['stack'] = true;
            }
            $i++;
          }
          $charts[0]['label'] = "План: $commonPlanSum";
          $charts[0]['bars'] = ['show' => true, 'align' => 'center',];  //'barWidth' => 0.6,

          ksort($charts); //сортируем массив по ключам, иначе график не построится
        }
      }
    }

    $ratesDropdownData = ArrayHelper::map(
      $this->company->getRates()->andWhere(['isDeleted' => 0])->all(),
      'id', 'title');

    $employeesDropdownData = ArrayHelper::map(
      $this->company->getEmployees()->andWhere(['type' => User::TYPE_EMPLOYEE])->all(),
      'id', 'fullname');

    $chartModeData['items'] = [
      0 => 'Общий график',
      1 => 'Выполнение плана',
    ];

    return $this->render('ratesStats', [
      'searchModel' => $searchModel,
      'charts' => $charts,
      'ratesDropdownData' => $ratesDropdownData,
      'employeesDropdownData' => $employeesDropdownData,
      'chartModeData' => $chartModeData,
      'company' => $this->company,
    ]);
  }

  public function actionRatesPlanStats() {

    $searchModel = new RateValueSearch();
    $searchModel->scenario = RateValueSearch::SCENARIO_SEARCH_BY_RATE;
    $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

    //получаем все rateId не помеченные на удаление из таблицы планов
    /** @var RatePlan $allRatePlans [] */
    $allRatePlans = RatePlan::findAll(['checked' => 1]);
    $rateIds = [];
    foreach ($allRatePlans as $oneRatePlan) {
      $rateIds[] = $oneRatePlan->rateId;
    }

    $ratesDropdownData = ArrayHelper::map(
      $this->company->getRates()->andWhere(['id' => $rateIds, 'isDeleted' => 0])->all(),
      'id', 'title');

    $employeesDropdownData = ArrayHelper::map(
      $this->company->getEmployees()->andWhere(['type' => User::TYPE_EMPLOYEE])->all(),
      'id', 'fullname');

    return $this->render('ratesPlanStats', [
      'searchModel' => $searchModel,
      'dataProvider' => $dataProvider,

      'ratesDropdownData' => $ratesDropdownData,
      'employeesDropdownData' => $employeesDropdownData,
      'company' => $this->company,
    ]);
  }

  /**
   *   Эффективность рекламы
   *
   * @return mixed
   */
  public function actionAdStats() {

    $searchModel = new AdStatSearch();
    $searchModel->company = $this->company;
    $dataProvider = $searchModel->search(Yii::$app->request->queryParams);

    return $this->render('ratesAd', [
      'searchModel' => $searchModel,
      'dataProvider' => $dataProvider,
      'company' => $this->company,
    ]);
  }

  /**
   *   Эффективность рекламных кампаний
   *
   * @return mixed
   */
  public function actionAdLaunchesStats() {

    $dataProvider = [];
    $adTitle = "";
    $postParams = Yii::$app->request->bodyParams; //_csrf проверка ?

    $ads = $this->company->getAds()
      ->andWhere(['isDeleted' => 0, 'type' => Ad::TYPE_ADVERTISING])
      ->indexBy('id')
      ->all();

    $adsDropDown['selection'] = isset($postParams['ad']) ? $postParams['ad'] : 0;
    $adsDropDown['items'] = \yii\helpers\ArrayHelper::map($ads, 'id', 'title');

    if ($adsDropDown['selection']) {
      $searchModel = new AdLaunchSearch();
      $searchModel->adId = $adsDropDown['selection'];
      $searchModel->isDeleted = 0;
      $searchModel->scenario = AdLaunchSearch::SCENARIO_DEFAULT;
      $dataProvider = $searchModel->search($postParams);
      $dataProvider->pagination->pageSize = 7;
      $adTitle = $ads[$adsDropDown['selection']]->title;
    }

    return $this->render('ratesAdLaunch', [
      'dataProvider' => $dataProvider,
      'adsDropDown' => $adsDropDown,
      'adTitle' => $adTitle,
      'company' => $this->company,
    ]);
  }

  /**
   * Ajax редактирование показателей
   *
   * @return string|void
   */
  public function actionAjaxUpdate() {

    if (isset($_POST['hasEditable'])) {
      Yii::$app->response->format = Response::FORMAT_JSON;
      $model = new RateValue();
      if ($model->load($_POST)) {
        if ($model->validate()) {
          $model->employeeId = Yii::$app->user->id;

          $dStart = new DateTime($model->date);
          $dEnd = new DateTime();
          $dDiff = $dStart->diff($dEnd);
          if ($dDiff->days > RateValue::DAYS_ALLOWED) {
            return ['output' => '', 'message' => 'Данные можно вводить только в течение ' . RateValue::DAYS_ALLOWED . ' дней'];
          }

          // todo check if rate belongs to owner

          // ищем уже сохранненое значение
          /** @var RateValue $oldValue */
          $oldValue = RateValue::find()->andWhere([
            'date' => date('Y-m-d', strtotime($model->date)),
            'rateId' => $model->rateId,
            'employeeId' => $model->employeeId,
          ])->one();

          if ($oldValue) {
            $oldValue->value = $model->value;
            $oldValue->save();
          } else {
            $model->save(false);
          }

          return ['output' => $model->value, 'message' => ''];
        } else {
          return ['output' => '', 'message' => $model->hasErrors('value') ? 'Неправильно введено значение' : implode(', ', $model->getFirstErrors())];
        }
      } else {
        return ['output' => '', 'message' => ''];
      }
    }

    return $this->redirect(['my', 'companyId' => $this->company->id]);
  }

}
