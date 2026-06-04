<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MailRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules() : array
    {
        return [
            // [GOAL-L2-HEAL-04 2026-05-24] L7.2 F-02 P1 — SafeRemoteHost
            // rejects admin-supplied mail_host pointing at loopback /
            // link-local / RFC1918 / cloud-metadata IP ranges. Prevents
            // SMTP-driven internal-VPC probe + AWS metadata-service exfil
            // primitive. See app/Rules/SafeRemoteHost.php docblock for the
            // full forbidden-range list.
            'mail_host'       => ['required', 'string', 'max:190', new \App\Rules\SafeRemoteHost()],
            'mail_port'       => ['required', 'string', 'max:190'],
            'mail_username'   => ['required', 'string', 'max:190'],
            'mail_password'   => ['required', 'string', 'max:190'],
            'mail_encryption' => ['required', 'string', 'max:190'],
            'mail_from_name'  => ['required', 'string', 'max:190'],
            'mail_from_email' => ['required', 'string', 'max:190'],
        ];
    }
}
