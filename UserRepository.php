<?php

namespace App\Repositories\User;

use App\Models\Events;
use App\Models\EventUser;
use App\Models\EventWatcher;
use App\Models\Units;
use App\Models\User;
use App\Models\UserDetail;
use App\Models\UserDiploma;
use App\Models\VirtualCooperative;
use App\Repositories\Seminars\SeminarsRepositoryInterface;
use App\Services\Seminars\SeminarsServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use NamTran\LaravelMakeRepositoryService\Repository\BaseRepository;
use RexShijaku\SQLToLaravelBuilder\SQLToLaravelBuilder;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{

   protected $table = 'users';

   /**
    * Specify Model class name
    *
    * @return string
    */
   public function model()
   {
      return User::class;
   }

   /**
    * @param int $id
    * @param array $with
    * @return User|mixed|null
    */
   public function getById(int $id, $with = [])
   {
      return $this->newQuery()->query->with($with)->where('lg_id', $id)->first();
   }

   /**
    * @param int $login_id
    * @return getUserById|mixed
    */
   public function getUserById(int $login_id)
   {
      return $this->newQuery()->query->where('login', $login_id)->first();
   }

   /**
    * @param int $id
    * @param array $with
    * @return mixed
    */
   public function getUserByLgId(int $id, $with = [])
   {
      return $this->newQuery()->query->where('lg_id', $id)->with($with)->first();
   }

   /**
    * @param int $id
    * @return mixed
    */
   public function getUserByLgIdForJob(int $id)
   {
      return $this->newQuery()->query->where('lg_id', $id)->whereHas('events', $fn = function ($q) {
         return $q->where('status', Events::PLANNED);
      })->with(['events' => $fn])->first();
   }

   /**
    * @return string
    */
   public function getModel(): string
   {
      return $this->model();
   }

   /**
    * Возвращает id всех нижестоящих директоров
    *
    * @param $user
    * @return Collection
    */
   public function getDownstreamDirectorIdsFromDb($user): Collection
   {
      return $this->newQuery()->query
         ->select('id', 'structure_path', 'lg_id')
         ->whereColumn('lg_id', 'director_id')
         ->where('structure_path', 'like', '%-' . $user->lg_id . '-%')
         ->pluck('lg_id')
         ->prepend($user->lg_id);
   }

   /**
    * @param $where
    * @param $data
    * @return mixed
    */
   public function updateOrCreate($where, $data)
   {
      return $this->newQuery()->query->withoutGlobalScopes()->updateOrCreate($where, $data);
   }

   /**
    * @param $director_ids
    * @param $seminar_id
    * @param array $select
    * @return mixed
    */
   public function getSpeakersByDirectorIds($director_ids, $seminar_id, array $select = ['*'])
   {
      return $this->newQuery()->query->select($select)
         ->noBanned()
         ->with('detail')
         ->whereColumn('lg_id', 'director_id')
         ->whereIn('director_id', $director_ids)
         ->withCount('event_watchers')
         ->get()
         ->filter(function ($item) use ($seminar_id) {
            return in_array($seminar_id, json_decode($item['detail']->seminar_access, TRUE));
         });
   }

   /**
    * @param $director_ids
    * @param array $select
    * @return mixed
    */
   public function getSpeakersByDirectorIdsForTicket($director_ids, $select = ['*'])
   {
      return $this->newQuery()->query->whereIn('director_id', $director_ids)->noBanned()->get($select);
   }

   /**
    * @param $id
    * @return mixed
    */
   public function getUserParentById($id)
   {
      return $this->newQuery()->query->where('lg_id', $id)->first();
   }

   /**
    * @param $ids
    * @param $the_date_of_the_beginning
    * @param $expiration_date
    * @return mixed
    */
   private function userQueryCollectionForManagement($ids, $the_date_of_the_beginning, $expiration_date)
   {
      return $this->newQuery()->query->whereIn('director_id', $ids)->with([
         'units' => function ($query) use ($the_date_of_the_beginning, $expiration_date) {
            return $query
               ->select([
                  'type',
                  'product_id',
                  'consult_id',
                  'operation_id',
                  DB::raw("SUM(units.amount) as units"),
               ])
               ->whereBetween('date', [$the_date_of_the_beginning, $expiration_date])
               ->groupBy('operation_id');
         },
      ])->withCalculateCount($the_date_of_the_beginning, $expiration_date);
   }

   /**
    * @param $seminar
    * @param $ids
    * @param $the_date_of_the_beginning
    * @param $expiration_date
    * @return mixed
    */
   public function getUserParentByIdWithUnit($seminar, $ids, $the_date_of_the_beginning, $expiration_date)
   {
      $query = $this->userQueryCollectionForManagement($ids, $the_date_of_the_beginning, $expiration_date);

      if ($seminar->necessarily_passed) {
         $query->whereDoesntHave('events', function ($q) use ($seminar) {
            return $q->where('status', Events::PAST)
               ->where('seminar_id', $seminar->necessarily_passed);
         });
      } else {
         $query->whereHas('event_pivot', function ($q) {
            return $q->where('candidate', '<>', $this->model()::CANDIDATE);
         });
         //$query->havingRaw('level < ' . $seminar->level . ' OR corporate < ' . $seminar->jk . ' OR units_sum IS NULL OR units_sum < ' . $seminar->ed);
      }

      return $query;
   }

   /**
    * @param $event_id
    * @param $ids
    * @param $the_date_of_the_beginning
    * @param $expiration_date
    * @return mixed
    */
   public function getUserParentByIdWithUnitPassed($event_id, $ids, $the_date_of_the_beginning, $expiration_date)
   {
      $query = $this->userQueryCollectionForManagement($ids, $the_date_of_the_beginning, $expiration_date);

      $query->whereHas('event_pivot', function ($q) use ($event_id) {
         return $q->where('event_id', $event_id)->where('getting_deployment', Events::GETTING_DEPLOYMENT);
      });

      return $query;
   }

   /**
    * @param $ids
    * @return mixed
    */
   public function getUserByIds($ids)
   {
      return $this->newQuery()->query->whereIn('lg_id', $ids)->get()->groupBy('lg_id');
   }

   /**
    * @param $event_id
    * @param $seminar
    * @param $ids
    * @param $the_date_of_the_beginning
    * @param $expiration_date
    * @return mixed
    */
   public function getUserForEventCandidatesTab($event_id, $seminar, $ids, $the_date_of_the_beginning, $expiration_date)
   {
      $query = $this->userQueryCollectionForManagement($ids, $the_date_of_the_beginning, $expiration_date)
         ->whereHas('event_pivot', function ($q) use ($event_id) {
            return $q->where('event_id', $event_id)
               ->where('candidate', $this->model()::CANDIDATE);
         })
         ->with([
            'event_pivot' => function ($q) use ($event_id) {
               return $q->where('event_id', $event_id)
                  ->where('candidate', $this->model()::CANDIDATE);
            },
         ]);

      if ($seminar->necessarily_passed) {
         $query->whereHas('events', function ($q) use ($seminar) {
            return $q->where('status', Events::PAST)
               ->where('seminar_id', $seminar->necessarily_passed);
         });
      }

      $query->whereDoesntHave('event_pivot', function ($q) use ($event_id) {
         return $q->where('event_id', $event_id)->where('member', User::MEMBER);
      });

      return $query;
   }

   /**
    * @param $event_id
    * @param $seminar
    * @param $ids
    * @param $the_date_of_the_beginning
    * @param $expiration_date
    * @param $seminar_event_join
    * @return mixed
    */
   public function getUserForEventMembersTab($event_id, $seminar, $ids, $the_date_of_the_beginning, $expiration_date, $seminar_event_join = null)
   {
      return $this->userQueryCollectionForManagement($ids, $the_date_of_the_beginning, $expiration_date)
         ->with([
            'event_pivot' => function ($q) use ($event_id) {
               return $q->where('event_id', $event_id);
            },
         ])
         ->whereHas('event_pivot', function ($q) use ($event_id, $seminar_event_join) {
            return $q->where('event_id', $event_id)->where('member', User::MEMBER)
               ->when(($seminar_event_join && $seminar_event_join->in_process === Events::NOT_IN_PROCESS), function ($query) {
                  return $query->where('passed', EventUser::PASSED);
               });
         });
   }

   /**
    * @param $director_id
    * @param $seminar_id
    * @param array $filters
    * @return \Illuminate\Database\Query\Builder|mixed
    */
   public function getUsersForEventByDirectorId($director_id, $seminar_id, array $filters = [])
   {
      $query = DB::table($this->table)
         ->select(
            'users.login',
            'users.fio',
            'users.level',
            'events.id as event_id',
            'events.country as event_country',
            'events.event_full_name',
            'events.city as event_city',
            'events.start_event',
            'events.the_date_of_the_beginning',
            'events.expiration_date',
            'seminars.id as seminar_id',
            'seminars.ed as seminar_ed',
            'seminars.level as seminar_level',
            'seminars.jk as seminar_jk',
            DB::raw(
               '(select IFNULL(round(SUM(units.amount),2),0) as personal from `units` where `users`.`lg_id` = `units`.`consult_id` and
                        `date` between events.the_date_of_the_beginning and events.expiration_date) as `units_sum`'
            ),
            DB::raw(
               '(select IFNULL(SUM(`coop_count`),0) as virtual_corporate_count from `virtual_cooperatives` where `users`.`lg_id` = `virtual_cooperatives`.`consult_id`
                     and `date_create` between events.the_date_of_the_beginning and events.expiration_date) as `virtual_cooperative_sum` '
            ),
            DB::raw(
               '(select (IFNULL(COUNT(DISTINCT `operation_id`),0) + IFNULL(virtual_cooperative_sum,0)) as corporate_count from `units` where `users`.`lg_id` = `units`.`consult_id`
                     and `date` between events.the_date_of_the_beginning and events.expiration_date and `product_id` = ' . VirtualCooperative::CORPORATE . ') as `corporate` '
            ),
         )
         ->join('events_users', function ($join) {
            $join->on('users.id', '=', 'events_users.user_id');
            $join->where('candidate', '=', $this->model()::CANDIDATE)
               ->where('director_recommendation', '=', EventUser::NOT_PASSED);
         })
         ->join('events', function ($join) use ($seminar_id) {
            $join->on('events.id', '=', 'events_users.event_id');
            $join->where('events.seminar_id', '=', $seminar_id);
            $join->where('events.status', '=', Events::PLANNED);
         })
         ->join('seminars', 'seminars.id', '=', 'events.seminar_id')
         ->where('users.director_id', $director_id)
         //->havingRaw(DB::raw('`units_sum` IS NULL OR `units_sum` < `seminars`.`ed` OR `users`.`level` < `seminars`.`level` OR `corporate` < `seminars`.`jk`'))
         ->when($filters, function ($q) use ($filters) {
            return $q->when(isset($filters['fio']), function ($q) use ($filters) {
               $q->where('fio', '%' . $filters["fio"] . '%');
            })->when(isset($filters['event_full_name']), function ($q) use ($filters) {
               $q->where('event_full_name', 'LIKE', '%' . $filters["event_full_name"] . '%');
            })->when(isset($filters['user_login']), function ($q) use ($filters) {
               $q->where('login', '=', $filters['user_login']);
            })->when(isset($filters['expiration_date']), function ($q) use ($filters) {
               $q->where('expiration_date', '=', $filters['expiration_date']);
            })->when(isset($filters['jk']), function ($q) use ($filters) {
               $q->where('jk', '=', $filters['jk']);
            })->when(isset($filters['ed']), function ($q) use ($filters) {
               $q->where('ed', '=', $filters['ed']);
            });
         });

      return ($filters && $filters['sort']['column'])
         ? $query->orderBy($filters['sort']['column'], $filters['sort']['direction'])
         : $query;
   }

   /**
    * @param $director_id
    * @param $seminar_id
    * @param $filters
    * @param null $archive
    * @return \Illuminate\Database\Query\Builder|mixed
    */
   public function getCandidatesOrArchiveForEventByDirectorId($director_id, $seminar_id, $filters, $archive = FALSE)
   {
      $query = DB::table($this->table)
         ->select(
            'users.login',
            'users.id',
            'users.fio',
            'users.level',
            'events.id as event_id',
            'events.status as event_status',
            'events.country as event_country',
            'events.city as event_city',
            'events.start_event',
            'events.the_date_of_the_beginning',
            'events.expiration_date',
            'events.user_name as event_organizer',
            'seminars.id as seminar_id',
            'seminars.ed as seminar_ed',
            'seminars.level as seminar_level',
            'seminars.jk as seminar_jk',

            'events_users.director_recommendation as events_users_director_recommendation',

            DB::raw(
               '(select IFNULL(round(SUM(units.amount),2),0) as personal from `units` where `users`.`lg_id` = `units`.`consult_id` and
                            `date` between events.the_date_of_the_beginning and events.expiration_date) as `units_sum`'
            ),
            DB::raw(
               '(select IFNULL(SUM(`coop_count`),0) as virtual_corporate_count from `virtual_cooperatives` where `users`.`lg_id` = `virtual_cooperatives`.`consult_id`
                     and `date_create` between events.the_date_of_the_beginning and events.expiration_date) as `virtual_cooperative_sum` '
            ),
            DB::raw(
               '(select (IFNULL(COUNT(DISTINCT `operation_id`),0) + IFNULL(virtual_cooperative_sum,0)) as corporate_count from `units` where `users`.`lg_id` = `units`.`consult_id`
                     and `date` between events.the_date_of_the_beginning and events.expiration_date and `product_id` = ' . VirtualCooperative::CORPORATE . ') as `corporate` '
            ),
         )
         ->join('events_users', function ($join) use ($archive) {
            $join->on('users.id', '=', 'events_users.user_id');
            $join->when($archive == FALSE, function ($query) {
               $query->where('candidate', $this->model()::CANDIDATE);
            });
         })
         ->join('events', function ($join) use ($seminar_id) {
            $join->on('events.id', '=', 'events_users.event_id');
         })
         ->join('seminars', 'seminars.id', '=', 'events.seminar_id')
         ->where('users.director_id', $director_id)
         ->when($archive == TRUE, function ($query) {
            $query->where('events.status', '=', Events::PAST);
         })
         ->when($archive == FALSE, function ($query) {
            $query->where('events_users.director_recommendation', '=', EventUser::NOT_PASSED)
               ->where('events.status', '<>', Events::PAST);
         })
         ->when($filters, function ($q) use ($filters) {
            return $q->when(isset($filters['user_name']), function ($q) use ($filters) {
               $fio = $filters['user_name'];
               $q->where('user_name', 'LIKE', "%$fio%");
            })->when(isset($filters['country']), function ($q) use ($filters) {
               $q->where('country', 'like', '%' . $filters["country"] . '%');
            })->when(isset($filters['city']), function ($q) use ($filters) {
               $q->where('city', 'like', '%' . $filters["city"] . '%');
            })->when(isset($filters['fio']), function ($q) use ($filters) {
               $fio = $filters['fio'];
               $q->where('fio', 'LIKE', "%$fio%");
            })->when(isset($filters['user_login']), function ($q) use ($filters) {
               $q->where('login', '=', $filters['user_login']);
            })->when(isset($filters['start_event']), function ($q) use ($filters) {
               $q->where('start_event', '=', $filters['start_event']);
            })->when(isset($filters['status']), function ($q) use ($filters) {
               $q->where('events_users.director_recommendation', '=', $filters['status']);
            });
         });

      return ($filters && $filters['sort']['column'])
         ? $query->orderBy($filters['sort']['column'], $filters['sort']['direction'])
         : $query;
   }

   /**
    * @param $user_id
    * @param $event_id
    * @return mixed
    */
   public function confirmEventUserPivotTable($user_id, $event_id)
   {
      $user = $this->newQuery()->query->where('id', $user_id)->first();
      $pivot = $user->event_pivot()->where('event_id', $event_id)->first();

      $pivot->update([
         'director_recommendation' => $this->model()::RECOMMENDED,
      ]);

      return $pivot;
   }

   /**
    * @param $user_id
    * @param $event_id
    * @param $comment
    * @return mixed
    */
   public function refuseEventUserPivotTable($user_id, $event_id, $comment)
   {
      $user = $this->newQuery()->query->where('id', $user_id)->first();

      $pivot = $user->event_pivot()->where('event_id', $event_id)->first();

      $pivot->update([
         'director_recommendation' => $this->model()::REFUSED,
         'director_rejection_reason' => $comment,
      ]);

      return $pivot;
   }

   /**
    * @param $event_user_pivot_id
    * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|object|null
    */
   public function confirmCandidateForEvent($event_user_pivot_id)
   {
      $eventUser = EventUser::query()->where('id', $event_user_pivot_id)->first();

      $eventUser->update([
         'organizer_confirmation' => $this->model()::ORGANIZER_ANSWER_RECOMMENDED,
      ]);

      return $eventUser;
   }

   /**
    * @param $event_user_pivot_id
    * @param $comment
    * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|object|null
    */
   public function refusedCandidateForEvent($event_user_pivot_id, $comment)
   {
      $eventUser = EventUser::query()->where('id', $event_user_pivot_id)->first();

      $eventUser->update([
         'organizer_confirmation' => $this->model()::ORGANIZER_ANSWER_REFUSE,
         'organizer_rejection_reason' => $comment,
      ]);

      return $eventUser;
   }

   /**
    * @param $event_user_pivot_id
    * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|object|null
    */
   public function setCandidateToEventMember($event_user_pivot_id)
   {
      $event_pivot = EventUser::query()->where('id', $event_user_pivot_id)->first();

      $event_pivot->update([
         'member' => $this->model()::MEMBER,
      ]);

      return $event_pivot;
   }

   /**
    * @param $event_user_pivot_id
    * @return int
    */
   public function setUserCandidateToEvent($event_user_pivot_id)
   {
      return EventUser::query()->where('id', $event_user_pivot_id)->update([
         'candidate' => $this->model()::CANDIDATE,
      ]);
   }

   /**
    * Делает расчет единиц текущего пользователя на определеный период даты [>  <]
    *
    * @param $user_id
    * @param $expiration_date
    * @param $the_date_of_the_beginning
    * @return mixed
    */
   public function getUserUnitsBetweenDate($user_id, $expiration_date, $the_date_of_the_beginning)
   {
      return $this->newQuery()->query->withoutGlobalScopes()->where('lg_id', $user_id)
         ->with([
            'units' => function ($query) use ($the_date_of_the_beginning, $expiration_date) {
               return $query
                  ->select([
                     'type',
                     'product_id',
                     'consult_id',
                     'operation_id',
                     DB::raw("SUM(units.amount) as units"),
                  ])
                  ->whereBetween('date', [$the_date_of_the_beginning, $expiration_date])
                  ->groupBy('operation_id');
            },
         ])
         ->withCount([
            'units AS units_sum' => function ($query) {
               return $query->select(DB::raw("round(SUM(units.amount),2) as personal"));
            },
            'virtual_cooperatives as virtual_cooperative_sum' => function ($query) use ($the_date_of_the_beginning, $expiration_date) {
               return $query
                  ->select(DB::raw("IFNULL(SUM(`coop_count`),0) as virtual_corporate_count"))
                  ->whereBetween('date_create', [$the_date_of_the_beginning, $expiration_date]);
            },
            'units as corporate' => function ($query) use ($the_date_of_the_beginning, $expiration_date) {
               return $query
                  ->select(DB::raw("(IFNULL(COUNT(DISTINCT `operation_id`),0) + IFNULL(virtual_cooperative_sum,0)) as corporate_count"))
                  ->whereBetween('date', [$the_date_of_the_beginning, $expiration_date])
                  ->where('product_id', VirtualCooperative::CORPORATE);
            },
         ])
         ->first();
   }

   /**
    * @return mixed
    */
   public function getUserForSpeakerFilter()
   {
      return $this->newQuery()->query->with(['detail'])
         ->where('level', '>=', UserDetail::MIN_SPEAKER_LEVEL)
         ->select('id', 'level');
   }

   /**
    * @param $inserts
    * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model
    */
   public function createDiploma($inserts)
   {
      return UserDiploma::query()->create($inserts);
   }

   /**
    * Возвращает всех наблюдателей которых одобрил организатор
    * @param $event_id
    * @param $seminar
    * @param $ids
    * @param $the_date_of_the_beginning
    * @param $expiration_date
    * @return mixed
    */
   public function getUserForEventWatcherTab($event_id, $ids, $the_date_of_the_beginning, $expiration_date)
   {
      return $this->userQueryCollectionForManagement($ids, $the_date_of_the_beginning, $expiration_date)
         ->whereHas('event_watchers', function ($q) use ($event_id) {
            return $q->where('event_id', $event_id);
         })
         ->with([
            'event_watchers' => function ($q) use ($event_id) {
               return $q->where('event_id', $event_id);
            },
         ]);
   }

   /**
    * @param $seminar
    * @param $magister_id
    * @param $filters
    * @return mixed
    */
   public function getCandidatesForLicense($seminar, $magister_id, $filters = NULL)
   {
      $rFunction = function ($q) use ($seminar) {
         return $q->where('permission_id', $seminar->permission_id);
      };

      $query = $this->model->where('structure_path', 'like', '%-' . $magister_id . '-%')
         ->where('level', '>=', $seminar->license_level)
         ->whereDoesntHave('permissions', $rFunction)
         ->having('watchers_count', '>=', $seminar->count_of_watching)
         ->whereDoesntHave('suspended_licenses', $rFunction)
         ->withCount([
            'watchers' => function ($q) use ($seminar) {
               return $q->whereHas('event', function ($query) use ($seminar) {
                  return $query->where('seminar_id', $seminar->id);
               })->confirmed();
            },
         ])
         ->when((!empty($filters) && isset($filters['watchers_count'])), function ($q) use ($filters) {
            $q->having('watchers_count', '=', $filters['watchers_count']);
         })
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->where(function ($query) use ($filters) {
               $query->filter($filters);
            });
         });

      return ($filters && isset($filters['sort']) && isset($filters['sort']['column'])) && $filters['sort']['column']
         ? $query->orderBy($filters['sort']['column'], $filters['sort']['direction'])
         : $query;
   }

   /**
    * @param $user_id
    * @param $seminar
    * @return mixed|void
    */
   public function addCertifyByUser($user_id, $seminar)
   {
      $user = $this->newQuery()->query->find($user_id);
      $user->givePermissionTo($seminar->permission_id);
   }

   /**
    * @param $seminar
    * @param $magister_id
    * @param $filters
    * @return mixed
    */
   public function getLicensesUser($seminar, $magister_id, $filters = NULL)
   {
      $query = $this->model->where('structure_path', 'like', '%-' . $magister_id . '-%')
         ->with('sertifikat', function ($q) use ($seminar) {
            $q->select('user_id', 'media')->where('seminar_id', $seminar->id);
         })
         ->whereHas('permissions', function ($q) use ($seminar) {
            return $q->where('permission_id', $seminar->permission_id);
         })->withCount([
            'watchers' => function ($q) use ($seminar) {
               return $q->whereHas('event', function ($query) use ($seminar) {
                  return $query->where('seminar_id', $seminar->id);
               })->confirmed();
            },
         ])
         ->when((!empty($filters) && isset($filters['watchers_count'])), function ($q) use ($filters) {
            $q->having('watchers_count', '=', $filters['watchers_count']);
         })
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->where(function ($query) use ($filters) {
               $query->filter($filters);
            });
         });

      return ($filters && isset($filters['sort']) && isset($filters['sort']['column'])) && $filters['sort']['column']
         ? $query->orderBy($filters['sort']['column'], $filters['sort']['direction'])
         : $query;
   }

   /**
    * @param $seminar
    * @param $magister_id
    * @param null $filters
    * @return mixed
    */
   public function getSuspendedUsers($seminar, $magister_id, $filters = NULL)
   {
      $query = $this->model->where('structure_path', 'like', '%-' . $magister_id . '-%')
         ->whereHas('suspended_licenses', function ($q) use ($seminar) {
            return $q->where('permission_id', $seminar->permission_id);
         })->withCount([
            'watchers' => function ($q) use ($seminar) {
               return $q->whereHas('event', function ($query) use ($seminar) {
                  return $query->where('seminar_id', $seminar->id);
               })->confirmed();
            },
         ])
         ->when((!empty($filters) && isset($filters['watchers_count'])), function ($q) use ($filters) {
            $q->having('watchers_count', '=', $filters['watchers_count']);
         })
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->where(function ($query) use ($filters) {
               $query->filter($filters);
            });
         });

      return ($filters && isset($filters['sort']) && isset($filters['sort']['column'])) && $filters['sort']['column']
         ? $query->orderBy($filters['sort']['column'], $filters['sort']['direction'])
         : $query;
   }

   /**
    * @param $magister
    * @return mixed
    */
   public function getDirectorsForMagister($magister)
   {
      return $this->model->where('magistr_id', $magister->lg_id)
         ->whereColumn('lg_id', 'director_id')->get()->pluck('fio', 'lg_id');
   }

   /**
    * @param $user_id
    * @param $seminar
    * @return mixed|void
    */
   public function addSuspendForUser($user_id, $seminar)
   {
      $user = $this->newQuery()->query->find($user_id);
      $user->revokePermissionTo($seminar->permission_id);
      $user->suspended_licenses()->create([
         'permission_id' => $seminar->permission_id,
      ]);
   }

   /**
    * @param $user_id
    * @param $seminar
    * @return mixed|void
    */
   public function resumeForUser($user_id, $seminar)
   {
      $user = $this->newQuery()->query->find($user_id);
      $user->givePermissionTo($seminar->permission_id);
      $user->suspended_licenses()->delete();
   }

   /**
    * @param $magister_id
    * @param array $filters
    * @return mixed
    */
   public function getUsersForStatisticForMagister($magister_id, array $filters = [])
   {
      $sortColumn = $filters['sort']['column'];
      $sortDirection = $filters['sort']['direction'];

      return $this->model->where('users.magistr_id', $magister_id)
         ->where('users.lg_id', '!=', $magister_id)
         ->usersNotPassedEvent()
         ->filter($filters)
         ->orderby($sortColumn, $sortDirection);
   }

   /**
    * @param $director_id
    * @param array $filters
    * @return mixed
    */
   public function getUsersForStatisticForDirector($director_id, array $filters = [])
   {
      $sortColumn = $filters['sort']['column'];
      $sortDirection = $filters['sort']['direction'];

      return $this->model->where('users.director_id', $director_id)
         ->where('users.lg_id', '!=', $director_id)
         ->usersNotPassedEvent()
         ->filter($filters)
         ->orderby($sortColumn, $sortDirection);
   }

   public function getUsersForStatisticForMagisterWithEvent($magister_id, $role, array $filters = [])
   {
      $role = json_decode($role);
      $member = DB::table(DB::raw('(select users.id,
            users.fio,
            users.login,
            users.level,
            users.lg_id,
            users.director_id,
            events.country,
            events.city,
            events.event_date,
            events.id as event_id,
            events.time_start_event,
            events.event_full_name,
            "Участник" as status
            from `users`
            inner join `events_users` on `events_users`.`user_id` = `users`.`id` and `member` = ' . $this->model()::MEMBER . '
            inner join `events` on `events`.`id` = `events_users`.`event_id`
            where `users`.`magistr_id` = ' . $magister_id . ' and `users`.`lg_id` != ' . $magister_id . ') `members`'))
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->when(isset($filters['user_fio']), function ($q) use ($filters) {
               $fio = $filters['user_fio'];
               $q->where('fio', 'LIKE', "%$fio%");
            })->when(isset($filters['user_login']), function ($q) use ($filters) {
               $q->where('login', '=', $filters['user_login']);
            })->when(isset($filters['user_level']), function ($q) use ($filters) {
               $q->where('level', '=', $filters['user_level']);
            })->when(isset($filters['event_month']), function ($q) use ($filters) {

               $date = is_string($filters['event_month'])
                  ? getNumberFromNameMonthOrYear($filters['event_month'])
                  : $filters['event_month'];

               if (is_array($date) && isset($date['month']) && $date['month']) {
                  $q->whereMonth('time_start_event', '=', $date['month']);
               }
            })->when(isset($filters['event_year']), function ($q) use ($filters) {
               $q->whereYear('time_start_event', '=', $filters['event_year']);
            })->when(isset($filters['event_full_name']), function ($q) use ($filters) {
               $full = $filters['event_full_name'];
               $q->where('event_full_name', 'LIKE', "%$full%");
            })->when(isset($filters['director_name']), function ($q) use ($filters) {
               $directorName = $filters['director_name'];
               $q->where('fio', 'LIKE', "%$directorName%")->whereColumn('lg_id', 'director_id');
            });
         });

      $watchers = DB::table(DB::raw('(select users.id,
            users.fio,
            users.login,
            users.level,
            users.lg_id,
            users.director_id,
            events.country,
            events.city,
            events.event_date,
            events.id as event_id,
            events.time_start_event,
            events.event_full_name,
            "Наблюдатель" as status from `users`
            inner join `event_watchers` on `event_watchers`.`user_id` = `users`.`id` and `status` = ' . $this->model()::MEMBER . '
            inner join `events` on `events`.`id` = `event_watchers`.`event_id`
            where `users`.`magistr_id` = ' . $magister_id . ' and `users`.`lg_id` != ' . $magister_id . ') `watchers`'))
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->when(isset($filters['user_fio']), function ($q) use ($filters) {
               $fio = $filters['user_fio'];
               $q->where('fio', 'LIKE', "%$fio%");
            })->when(isset($filters['user_login']), function ($q) use ($filters) {
               $q->where('login', '=', $filters['user_login']);
            })->when(isset($filters['user_level']), function ($q) use ($filters) {
               $q->where('level', '=', $filters['user_level']);
            })->when(isset($filters['event_month']), function ($q) use ($filters) {

               $date = is_string($filters['event_month'])
                  ? getNumberFromNameMonthOrYear($filters['event_month'])
                  : $filters['event_month'];

               if (is_array($date) && isset($date['month']) && $date['month']) {
                  $q->whereMonth('time_start_event', '=', $date['month']);
               }
            })->when(isset($filters['event_year']), function ($q) use ($filters) {
               $q->whereYear('time_start_event', '=', $filters['event_year']);
            })->when(isset($filters['event_full_name']), function ($q) use ($filters) {
               $full = $filters['event_full_name'];
               $q->where('event_full_name', 'LIKE', "%$full%");
            })->when(isset($filters['director_name']), function ($q) use ($filters) {
               $directorName = $filters['director_name'];
               $q->where('fio', 'LIKE', "%$directorName%")->whereColumn('lg_id', 'director_id');
            });
         });

      $speakers = DB::table(DB::raw('(select users.id,
            users.fio,
            users.login,
            users.level,
            users.lg_id,
            users.director_id,
            events.country,
            events.city,
            events.event_date,
            events.id as event_id,
            events.time_start_event,
            events.event_full_name,
            "Лектор" as status from `users`
            inner join `event_speakers` on `event_speakers`.`user_id` = `users`.`id` and `status` = ' . $this->model()::MEMBER . '
            inner join `events` on `events`.`id` = `event_speakers`.`event_id`
            where `users`.`magistr_id` = ' . $magister_id . ' and `users`.`lg_id` != ' . $magister_id . ') `speakers`'))
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->when(isset($filters['user_fio']), function ($q) use ($filters) {
               $fio = $filters['user_fio'];
               $q->where('fio', 'LIKE', "%$fio%");
            })->when(isset($filters['user_login']), function ($q) use ($filters) {
               $q->where('login', '=', $filters['user_login']);
            })->when(isset($filters['user_level']), function ($q) use ($filters) {
               $q->where('level', '=', $filters['user_level']);
            })->when(isset($filters['event_month']), function ($q) use ($filters) {

               $date = is_string($filters['event_month'])
                  ? getNumberFromNameMonthOrYear($filters['event_month'])
                  : $filters['event_month'];

               if (is_array($date) && isset($date['month']) && $date['month']) {
                  $q->whereMonth('time_start_event', '=', $date['month']);
               }
            })->when(isset($filters['event_year']), function ($q) use ($filters) {
               $q->whereYear('time_start_event', '=', $filters['event_year']);
            })->when(isset($filters['event_full_name']), function ($q) use ($filters) {
               $full = $filters['event_full_name'];
               $q->where('event_full_name', 'LIKE', "%$full%");
            })->when(isset($filters['director_name']), function ($q) use ($filters) {
               $directorName = $filters['director_name'];
               $q->where('fio', 'LIKE', "%$directorName%")->whereColumn('lg_id', 'director_id');
            });
         });

      $organizer = DB::table(DB::raw('(select
            users.id,
            users.fio,
            users.login,
            users.level,
            users.lg_id,
            users.director_id,
            events.country,
            events.city,
            events.event_date,
            events.id as event_id,
            events.time_start_event,
            events.event_full_name,
            "Организатор" as status from `users`
            inner join `events` on `events`.`user_id` = `users`.`id`
            where `users`.`magistr_id` = ' . $magister_id . ' and `users`.`lg_id` != ' . $magister_id . ') `organizer`'))
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->when(isset($filters['user_fio']), function ($q) use ($filters) {
               $fio = $filters['user_fio'];
               $q->where('fio', 'LIKE', "%$fio%");
            })->when(isset($filters['user_login']), function ($q) use ($filters) {
               $q->where('login', '=', $filters['user_login']);
            })->when(isset($filters['user_level']), function ($q) use ($filters) {
               $q->where('level', '=', $filters['user_level']);
            })->when(isset($filters['event_month']), function ($q) use ($filters) {

               $date = is_string($filters['event_month'])
                  ? getNumberFromNameMonthOrYear($filters['event_month'])
                  : $filters['event_month'];

               if (is_array($date) && isset($date['month']) && $date['month']) {
                  $q->whereMonth('time_start_event', '=', $date['month']);
               }
            })->when(isset($filters['event_year']), function ($q) use ($filters) {
               $q->whereYear('time_start_event', '=', $filters['event_year']);
            })->when(isset($filters['event_full_name']), function ($q) use ($filters) {
               $full = $filters['event_full_name'];
               $q->where('event_full_name', 'LIKE', "%$full%");
            })->when(isset($filters['director_name']), function ($q) use ($filters) {
               $directorName = $filters['director_name'];
               $q->where('fio', 'LIKE', "%$directorName%")->whereColumn('lg_id', 'director_id');
            });
         });

      $sortColumn = $filters['sort']['column'];
      $sortDirection = $filters['sort']['direction'];

      if ($role && $role->status != 'all') {
         return $this->getStatisticsRoleByMagisterOrByDirector($member, $watchers, $speakers, $organizer, $role)->orderByRaw("$sortColumn $sortDirection");
      }

      return $member
         ->unionAll($watchers)
         ->unionAll($speakers)
         ->unionAll($organizer)
         ->orderByRaw("$sortColumn $sortDirection");
   }

   /**
    * @param $director_id
    * @param $role
    * @param $filters
    * @return mixed
    */
   public function getUsersForStatisticForDirectorWithEvent($director_id, $role, $filters = [])
   {
      $role = json_decode($role);
      $member = DB::table(DB::raw('(select users.id,
            users.fio,
            users.login,
            users.level,
            users.director_id,
            events.event_full_name,
            events.country,
            events.city,
            events.event_date,
            events.id as event_id,
            events.time_start_event,
            "Участник" as status
            from `users`
            inner join `events_users` on `events_users`.`user_id` = `users`.`id` and `member` = ' . $this->model()::MEMBER . '
            inner join `events` on `events`.`id` = `events_users`.`event_id`
            where `users`.`director_id` = ' . $director_id . ' and `users`.`lg_id` != ' . $director_id . ') `members`'))
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->when(isset($filters['user_fio']), function ($q) use ($filters) {
               $fio = $filters['user_fio'];
               $q->where('fio', 'LIKE', "%$fio%");
            })->when(isset($filters['user_login']), function ($q) use ($filters) {
               $q->where('login', '=', $filters['user_login']);
            })->when(isset($filters['user_level']), function ($q) use ($filters) {
               $q->where('level', '=', $filters['user_level']);
            })->when(isset($filters['event_month']), function ($q) use ($filters) {

               $date = is_string($filters['event_month'])
                  ? getNumberFromNameMonthOrYear($filters['event_month'])
                  : $filters['event_month'];

               if (is_array($date) && isset($date['month']) && $date['month']) {
                  $q->whereMonth('time_start_event', '=', $date['month']);
               }
            })->when(isset($filters['event_year']), function ($q) use ($filters) {
               $q->whereYear('time_start_event', '=', $filters['event_year']);
            })->when(isset($filters['event_full_name']), function ($q) use ($filters) {
               $full = $filters['event_full_name'];
               $q->where('event_full_name', 'LIKE', "%$full%");
            });
         });

      $watchers = DB::table(DB::raw('(select users.id,
            users.fio,
            users.login,
            users.level,
            users.director_id,
            events.event_full_name,
            events.country,
            events.city,
            events.event_date,
            events.id as event_id,
            events.time_start_event,
            "Наблюдатель" as status from `users`
            inner join `event_watchers` on `event_watchers`.`user_id` = `users`.`id` and `status` = ' . $this->model()::MEMBER . '
            inner join `events` on `events`.`id` = `event_watchers`.`event_id`
            where `users`.`director_id` = ' . $director_id . ' and `users`.`lg_id` != ' . $director_id . ') `watchers`'))
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->when(isset($filters['user_fio']), function ($q) use ($filters) {
               $fio = $filters['user_fio'];
               $q->where('fio', 'LIKE', "%$fio%");
            })->when(isset($filters['user_login']), function ($q) use ($filters) {
               $q->where('login', '=', $filters['user_login']);
            })->when(isset($filters['user_level']), function ($q) use ($filters) {
               $q->where('level', '=', $filters['user_level']);
            })->when(isset($filters['event_month']), function ($q) use ($filters) {

               $date = is_string($filters['event_month'])
                  ? getNumberFromNameMonthOrYear($filters['event_month'])
                  : $filters['event_month'];

               if (is_array($date) && isset($date['month']) && $date['month']) {
                  $q->whereMonth('time_start_event', '=', $date['month']);
               }
            })->when(isset($filters['event_year']), function ($q) use ($filters) {
               $q->whereYear('time_start_event', '=', $filters['event_year']);
            })->when(isset($filters['event_full_name']), function ($q) use ($filters) {
               $full = $filters['event_full_name'];
               $q->where('event_full_name', 'LIKE', "%$full%");
            });
         });

      $speakers = DB::table(DB::raw('(select users.id,
            users.fio,
            users.login,
            users.level,
            users.director_id,
            events.event_full_name,
            events.country,
            events.city,
            events.event_date,
            events.id as event_id,
            events.time_start_event,
            "Лектор" as status from `users`
            inner join `event_speakers` on `event_speakers`.`user_id` = `users`.`id` and `status` = ' . $this->model()::MEMBER . '
            inner join `events` on `events`.`id` = `event_speakers`.`event_id`
            where `users`.`director_id` = ' . $director_id . ' and `users`.`lg_id` != ' . $director_id . ') `speakers`'))
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->when(isset($filters['user_fio']), function ($q) use ($filters) {
               $fio = $filters['user_fio'];
               $q->where('fio', 'LIKE', "%$fio%");
            })->when(isset($filters['user_login']), function ($q) use ($filters) {
               $q->where('login', '=', $filters['user_login']);
            })->when(isset($filters['user_level']), function ($q) use ($filters) {
               $q->where('level', '=', $filters['user_level']);
            })->when(isset($filters['event_month']), function ($q) use ($filters) {

               $date = is_string($filters['event_month'])
                  ? getNumberFromNameMonthOrYear($filters['event_month'])
                  : $filters['event_month'];

               if (is_array($date) && isset($date['month']) && $date['month']) {
                  $q->whereMonth('time_start_event', '=', $date['month']);
               }
            })->when(isset($filters['event_year']), function ($q) use ($filters) {
               $q->whereYear('time_start_event', '=', $filters['event_year']);
            })->when(isset($filters['event_full_name']), function ($q) use ($filters) {
               $full = $filters['event_full_name'];
               $q->where('event_full_name', 'LIKE', "%$full%");
            });
         });

      $organizer = DB::table(DB::raw('(select users.id,
            users.fio,
            users.login,
            users.level,
            users.director_id,
            events.event_full_name,
            events.country,
            events.city,
            events.event_date,
            events.id as event_id,
            events.time_start_event,
            "Организатор" as status from `users`
            inner join `events` on `events`.`user_id` = `users`.`id`
            where `users`.`director_id` = ' . $director_id . ' and `users`.`lg_id` != ' . $director_id . ') `organizer`'))
         ->when(!empty($filters), function ($q) use ($filters) {
            $q->when(isset($filters['user_fio']), function ($q) use ($filters) {
               $fio = $filters['user_fio'];
               $q->where('fio', 'LIKE', "%$fio%");
            })->when(isset($filters['user_login']), function ($q) use ($filters) {
               $q->where('login', '=', $filters['user_login']);
            })->when(isset($filters['user_level']), function ($q) use ($filters) {
               $q->where('level', '=', $filters['user_level']);
            })->when(isset($filters['event_month']), function ($q) use ($filters) {

               $date = is_string($filters['event_month'])
                  ? getNumberFromNameMonthOrYear($filters['event_month'])
                  : $filters['event_month'];

               if (is_array($date) && isset($date['month']) && $date['month']) {
                  $q->whereMonth('time_start_event', '=', $date['month']);
               }
            })->when(isset($filters['event_year']), function ($q) use ($filters) {
               $q->whereYear('time_start_event', '=', $filters['event_year']);
            })->when(isset($filters['event_full_name']), function ($q) use ($filters) {
               $full = $filters['event_full_name'];
               $q->where('event_full_name', 'LIKE', "%$full%");
            });
         });

      $sortColumn = $filters['sort']['column'];
      $sortDirection = $filters['sort']['direction'];

      if ($role && $role->status != 'all') {
         return $this->getStatisticsRoleByMagisterOrByDirector($member, $watchers, $speakers, $organizer, $role)->orderByRaw("$sortColumn $sortDirection");
      }

      return $member
         ->unionAll($watchers)
         ->unionAll($speakers)
         ->unionAll($organizer)
         ->orderByRaw("$sortColumn $sortDirection");
   }

   /**
    * @param $member
    * @param $watchers
    * @param $speakers
    * @param $organizer
    * @param $role
    * @return mixed
    */
   public function getStatisticsRoleByMagisterOrByDirector($member, $watchers, $speakers, $organizer, $role)
   {
      if ($role->status == "members")
         return $member;
      else if ($role->status == "watchers")
         return $watchers;
      else if ($role->status == "speakers")
         return $speakers;
      else
         return $organizer;
   }

   /**
    * Проверяет может лы консултант стать участником текушего мероприятие
    * @param int $seminar_id
    * @param int $seminar_level
    * @param $necessarily_passed
    * @param $seminar_necessarily_passed
    * @param array $directors
    * @return mixed|void
    */
   public function getUsersEachHaveAccessToEvent(int $seminar_id, int $seminar_level, $necessarily_passed, $seminar_necessarily_passed, array $directors)
   {
      return $this->newQuery()->query
         ->where('level', '>=', $seminar_level)
         ->whereIn('director_id', $directors)
         ->whereDoesntHave('past_events', function ($query) use ($seminar_id) {
            return $query->member()->where('permission_id', $seminar_id);
         })->when($seminar_necessarily_passed, function ($query) use ($necessarily_passed) {
            /**
             * Текущый запрось проверяет следующее...
             *
             * seminar->necessarily_passed
             * Например чтобы пройты Семинар (RT-2) Надо чтобы Кандидат был прошедшем в RT-1 (Event::PASSED = (3) )
             */
            return $query->whereHas('past_events', function ($subQuery) use ($necessarily_passed) {
               return $subQuery->member()->where('permission_id', $necessarily_passed);
            });
         })
         ->get();
   }

}
