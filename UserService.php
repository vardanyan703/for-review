<?php

namespace App\Services\User;

use App\Models\Events;
use App\Models\User;
use App\Notifications\SmsConfirmation;
use App\Repositories\Seminars\SeminarsRepositoryInterface;
use App\Services\Seminars\SeminarsServiceInterface;
use App\Services\User\UserServiceInterface;
use App\Services\BaseService;
use App\Repositories\User\UserRepositoryInterface;
use App\Traits\Services\PdfGenerateAble;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserService extends BaseService implements UserServiceInterface
{
   use PdfGenerateAble;
   
   /**
    * @var int
    */
   public $limitMinuteValidSms = 5;
   
   /**
    * @var UserRepositoryInterface
    */
   private $userRepository;
   
   /**
    * UserService constructor.
    * @param UserRepositoryInterface $userRepository
    * @param Request $request
    */
   public function __construct(UserRepositoryInterface $userRepository, Request $request)
   {
      parent::__construct($request);
      $this->userRepository = $userRepository;
   }
   
   /**
    * @return UserRepositoryInterface
    */
   public function getUserRepo(): UserRepositoryInterface
   {
      return $this->userRepository;
   }
   
   /**
    * @param $id
    * @param array $with
    * @return get|mixed
    */
   public function getUserByLgId($id, $with = [])
   {
      return $this->userRepository->getUserByLgId($id, $with);
   }
   
   /**
    * @param $id
    * @return get|array
    */
   public function getParent($id)
   {
      $user = $this->getUserByLgId($id);
      $parent = [];
      if ($user->lg_id == $user->magistr_id) {
         $parent[] = $user;
         $parent[] = $this->userRepository->getUserParentById($user->uid_parent);
      }
      if ($user->lg_id == $user->director_id && $user->lg_id != $user->magistr_id) {
         $parent[] = $this->userRepository->getUserParentById($user->uid_parent);
      }
      if ($user->lg_id != $user->director_id && $user->lg_id != $user->magistr_id) {
         $parent[] = $this->userRepository->getUserParentById($user->director_id);
      }
      
      return $parent;
   }
   
   /**
    * @param $event_user_pivot_id
    * @return mixed
    */
   public function setUserCandidateToEvent($event_user_pivot_id)
   {
      return $this->userRepository->setUserCandidateToEvent($event_user_pivot_id);
   }
   
   /**
    * @param $where
    * @param $data
    * @return mixed
    */
   public function updateOrCreate($where, $data)
   {
      return $this->userRepository->updateOrCreate($where, $data);
   }
   
   /**
    * @param $id
    * @param $status
    * @return mixed
    */
   public function setSpeaker($id, $status)
   {
      return $this->userRepository->updateById($id, [
         'speaker' => (int)$status,
      ]);
   }
   
   /**
    * @param $director_ids
    * @param $seminar_id
    * @return mixed
    */
   public function getSpeakersByDirectorIds($director_ids, $seminar_id)
   {
      return $this->userRepository->getSpeakersByDirectorIds($director_ids, $seminar_id, [
         'id',
         'fio as label',
         'director_id',
         'level',
      ]);
   }
   
   /**
    * @param $director_ids
    * @return mixed
    */
   public function getSpeakersByDirectorIdsForTicket($director_ids)
   {
      return $this->userRepository->getSpeakersByDirectorIdsForTicket($director_ids, [
         'id',
         'fio as label',
         'director_id',
         'level',
      ]);
   }
   
   /**
    * @return mixed
    */
   public function getUserForSpeakerFilter()
   {
      return $this->userRepository->getUserForSpeakerFilter();
   }
   
   /**
    * @param $license_id
    * @param $magister_id
    * @param null $filters
    * @param array $directors
    * @return mixed
    */
   public function getCandidatesForLicense($license_id, $magister_id, $filters = NULL, $directors = [])
   {
      $seminarService = app(SeminarsServiceInterface::class);
      
      $collection = $this->userRepository->getCandidatesForLicense($seminarService->getSeminarById($license_id), $magister_id, $filters);
      
      return $this->isExportData()
         ? $this->getDataForLicense($collection->get(), $directors)
         : $collection->paginate($this->getRequest()->request->get('limit') ?? 10);
   }
   
   /**
    * @param $user_id
    * @param $seminar_id
    * @return mixed
    */
   public function addCertify($user_id, $seminar_id)
   {
      $seminarService = app(SeminarsServiceInterface::class);
      $seminar = $seminarService->getSeminarById($seminar_id);
      $user = $this->userRepository->find($user_id);
      
      $fileName = $user->login . '-' . Str::slug($user->fio) . '-sertifikat' . '.pdf';
      $path = 'sertifikats-pdf/' . $seminar->id . '/' . $fileName;
      $this->generatePdfSertifikate($fileName, $seminar, $user, $this->getLastIdLicense(), $path);
      
      return $this->userRepository->addCertifyByUser($user_id, $seminarService->getSeminarById($seminar_id));
   }
   
   /**
    * @param $user_id
    * @param $seminar_id
    * @return mixed
    */
   public function addSuspendForUser($user_id, $seminar_id)
   {
      $seminarService = app(SeminarsServiceInterface::class);
      
      return $this->userRepository->addSuspendForUser($user_id, $seminarService->getSeminarById($seminar_id));
   }
   
   /**
    * @param $license_id
    * @param $magister_id
    * @param null $filters
    * @param array $directors
    * @return mixed
    */
   public function getLicenses($license_id, $magister_id, $filters = NULL, $directors = [])
   {
      $seminarService = app(SeminarsServiceInterface::class);
      
      $collection = $this->userRepository->getLicensesUser($seminarService->getSeminarById($license_id), $magister_id, $filters);
      
      return $this->isExportData()
         ? $this->getDataForLicense($collection->get(), $directors)
         : $collection->paginate($this->getRequest()->request->get('limit') ?? 10);
   }
   
   /**
    * @param $user
    * @return mixed
    */
   public function getDirectorsDown($user)
   {
      return $this->userRepository->getDirectorsForMagister($user);
   }
   
   /**
    * @param $license_id
    * @param $magister_id
    * @param null $filters
    * @param array $directors
    * @return mixed
    */
   public function getSuspendedUsers($license_id, $magister_id, $filters = NULL, $directors = [])
   {
      $seminarService = app(SeminarsServiceInterface::class);
      
      $collection = $this->userRepository->getSuspendedUsers($seminarService->getSeminarById($license_id), $magister_id, $filters);
      
      return $this->isExportData()
         ? $this->getDataForLicense($collection->get(), $directors)
         : $collection->paginate($this->getRequest()->request->get('limit') ?? 10);
   }
   
   /**
    * @param $user_id
    * @param $seminar_id
    * @return mixed
    */
   public function resumeForUser($user_id, $seminar_id)
   {
      $seminarService = app(SeminarsServiceInterface::class);
      
      return $this->userRepository->resumeForUser($user_id, $seminarService->getSeminarById($seminar_id));
   }
   
   /**
    * @param $magister_id
    * @param array $filters
    * @param array $directors
    * @return mixed
    */
   public function getUsersForStatisticForMagister($magister_id, $filters = [], $directors = [])
   {
      $collection = $this->userRepository->getUsersForStatisticForMagister($magister_id, $filters);
      
      return $this->isExportData()
         ? $this->getNotPassedData($collection->get(), $directors)
         : $collection->paginate($this->getRequest()->request->get('limit') ?? 10);
   }
   
   /**
    * @param $director_id
    * @param array $filters
    * @param array $directors
    * @return mixed
    */
   public function getUsersForStatisticForDirector($director_id, array $filters = [], $directors = [])
   {
      $collection = $this->userRepository->getUsersForStatisticForDirector($director_id, $filters);
      
      return $this->isExportData()
         ? $this->getNotPassedData($collection->get(), $directors)
         : $collection->paginate($this->getRequest()->request->get('limit') ?? 10);
   }
   
   /**
    * @param $magister_id
    * @param $role
    * @param array $filters
    * @param array $directors
    * @return mixed
    */
   public function getUsersForStatisticForMagisterWithEvent($magister_id, $role, array $filters = [], $directors = [])
   {
      $collection = $this->userRepository->getUsersForStatisticForMagisterWithEvent($magister_id, $role, $filters);
      
      return $this->isExportData()
         ? $this->getStatisticData($collection->get(), $directors)
         : $collection->paginate($this->getRequest()->request->get('limit') ?? 10);
   }
   
   /**
    * @param $director_id
    * @param $role
    * @param array $filters
    * @param array $directors
    * @return mixed
    */
   public function getUsersForStatisticForDirectorWithEvent($director_id, $role, array $filters = [], $directors = [])
   {
      $collection = $this->userRepository->getUsersForStatisticForDirectorWithEvent($director_id, $role, $filters);
      
      return $this->isExportData()
         ? $this->getStatisticData($collection->get(), $directors)
         : $collection->paginate($this->getRequest()->request->get('limit') ?? 10);
   }
   
   /**
    * @param $seminar_id
    * @return mixed|void
    */
   public function getSeminarPermission($seminar_id)
   {
      $seminarService = app(SeminarsRepositoryInterface::class);
      
      return $seminarService->getSeminarPermission($seminar_id);
   }
   
   /**
    * @param $collection
    * @param $directors
    * @return Collection|\Symfony\Component\HttpFoundation\BinaryFileResponse
    */
   public function getStatisticData($collection, $directors)
   {
      return $this->exportData(
         $collection,
         config('export_configs.statistics.statistics.headings'),
         function ($item) use ($directors) {
            $date = Carbon::parse($item->time_start_event)->format('m');
            $month = (int)explode('.', ceil($date))[0];
            
            return collect([
               $item->login,
               $item->fio,
               "Уровень $item->level",
               $directors[$item->director_id] ?? '',
               $item->event_full_name,
               getMonthRu($month),
               Carbon::parse($item->time_start_event)->format('Y'),
               $item->status,
            ]);
         }
      );
   }
   
   /**
    * @param $collection
    * @param $directors
    * @return Collection|\Symfony\Component\HttpFoundation\BinaryFileResponse
    */
   public function getNotPassedData($collection, $directors)
   {
      return $this->exportData(
         $collection,
         config('export_configs.statistics.not_passed.headings'),
         function ($item) use ($directors) {
            return collect([
               $item->login,
               $item->fio,
               "Уровень $item->level",
               $directors[$item->director_id] ?? '',
            ]);
         }
      );
   }
   
   /**
    * @param $collection
    * @param $directors
    * @return Collection|\Symfony\Component\HttpFoundation\BinaryFileResponse
    */
   public function getDataForLicense($collection, $directors)
   {
      return $this->exportData(
         $collection,
         config('export_configs.license.headings'),
         function ($item) use ($directors) {
            return collect([
               $item->login,
               $item->fio,
               $directors[$item->director_id] ?? '',
               $item->watchers_count,
            ]);
         }
      );
   }
   
   /**
    * @param $number
    * @param $user
    * @return array|string[]
    */
   public function checkValidNumberConfirmationSms($number, $user): array
   {
      $userSms = $user->unreadNotifications()
         ->where('type', '=', User::SMS_CONFIRMATION)
         ->where('data->number', '=', $number)
         ->first();
      
      return !$userSms ? [
         'code' => 404,
         'status' => 'error',
         'message' => 'Вы ввели неправильный код подтверждения',
      ] : ($this->limitMinuteValidSms < dateCalculate($userSms->created_at, 'minutes')
         ? [
            'code' => 403, // 403 Forbidden («запрещено (не уполномочен)»)
            'status' => 'error',
            'message' => 'Срок действия кода истек',
         ]
         : [
            'code' => 200, // 200 OK («хорошо»)
            'status' => 'success',
            'read_at' => $userSms->update(['read_at' => now()]),
            'message' => 'Проверка прошла успешно',
         ]
      );
   }
   
   /**
    * @param $user
    * @return mixed
    */
   public function checkExistsFreshNotification($user)
   {
      return $user->unreadNotifications()
         ->where('type', SmsConfirmation::class)
         ->where('created_at', '>', Carbon::now()->subMinutes($this->limitMinuteValidSms)->toDateTimeString())
         ->exists();
   }
   
   /**
    * @param int $seminar_id
    * @param int $seminar_level
    * @param $necessarily_passed
    * @param $seminar_necessarily_passed
    * @param array $directors
    * @return mixed
    */
   public function getUsersEachHaveAccessToEvent(int $seminar_id, int $seminar_level, $necessarily_passed, $seminar_necessarily_passed, array $directors)
   {
      return $this->userRepository->getUsersEachHaveAccessToEvent($seminar_id, $seminar_level, $necessarily_passed, $seminar_necessarily_passed, $directors);
   }
}
