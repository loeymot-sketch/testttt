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
        // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] every field here is
        // written verbatim into .env by MailService (EnvEditor::addData) —
        // same injection vector as company_name ([ULTRA-AUDIT V4-DEPLOY
        // 2026-07-02]): a raw \r/\n/" lets an admin-supplied value inject an
        // independent .env line (e.g. APP_DEBUG=true).
        $noEnvInjection = 'regex:/^[^\r\n"]*$/';

        return [
            // [GOAL-L2-HEAL-04 2026-05-24] L7.2 F-02 P1 — SafeRemoteHost
            // rejects admin-supplied mail_host pointing at loopback /
            // link-local / RFC1918 / cloud-metadata IP ranges. Prevents
            // SMTP-driven internal-VPC probe + AWS metadata-service exfil
            // primitive. See app/Rules/SafeRemoteHost.php docblock for the
            // full forbidden-range list.
            'mail_host'       => ['required', 'string', 'max:190', new \App\Rules\SafeRemoteHost(), $noEnvInjection],
            'mail_port'       => ['required', 'string', 'max:190', $noEnvInjection],
            'mail_username'   => ['required', 'string', 'max:190', $noEnvInjection],
            'mail_password'   => ['required', 'string', 'max:190', $noEnvInjection],
            'mail_encryption' => ['required', 'string', 'max:190', $noEnvInjection],
            'mail_from_name'  => ['required', 'string', 'max:190', $noEnvInjection],
            'mail_from_email' => ['required', 'string', 'max:190', $noEnvInjection],
        ];
    }
}
