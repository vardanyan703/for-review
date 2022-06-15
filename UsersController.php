<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\User\UserServiceInterface;

class UsersController extends Controller
{
    /**
     * @var UserServiceInterface
    */
    private $userService;
    /**
     * UserController constructor.
     * @param UserServiceInterface $userService
    */
    public function __construct(UserServiceInterface $userService)
    {
        $this->userService = $userService;
    }
    /**
     * @param $id
     * @return Array
    */
    public function getParentUsers($id): Array
    {
        return $this->userService->getParent($id);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function speakersByDirectorIds(Request $request)
    {
        return response()->json([
            'speakers' => $this->userService->getSpeakersByDirectorIds($request->directors,$request->seminar_id)
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function speakersByDirectorIdsForTicket(Request $request)
    {
        return response()->json([
            'speakers' => $this->userService->getSpeakersByDirectorIdsForTicket($request->directors)
        ]);
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function seminarPermission($id)
    {
        return response()->json([
            'permission' => $this->userService->getSeminarPermission($id)
        ]);
    }

}
