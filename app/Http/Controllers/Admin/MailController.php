<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\MailRequest;
use App\Http\Resources\MailResource;
use App\Services\MailService;
use Exception;

class MailController extends AdminController
{
    private MailService $mailService;

    public function __construct(MailService $mailService)
    {
        parent::__construct();
        $this->mailService = $mailService;
        // [GOAL-2026-05-30 SET-02] Gate index too: GET /admin/setting/mail returns the SMTP
        // config incl. mail_password in cleartext. Only the settings MailComponent consumes
        // this read (verified: sole `mail/lists` dispatch), so gating index does not break
        // any non-settings surface. Mirrors KioskSetup/LoyaltySetup (->only('index','update')).
        $this->middleware(['permission:settings'])->only('index', 'update');
    }

    public function index() : \Illuminate\Http\Response | MailResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new MailResource($this->mailService->list());
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function update(MailRequest $request) : \Illuminate\Http\Response | MailResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new MailResource($this->mailService->update($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}
