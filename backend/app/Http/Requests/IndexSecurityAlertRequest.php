<?php

namespace App\Http\Requests;

use App\Enums\SecurityAlertType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Authorization and filter validation for GET /v1/security-alerts (§10.4).
 *
 * Same shape as IndexAuditLogRequest, with a narrower Gate: these are addressed
 * to the DSI, which is `admin` here — see the migration for why data_owner is
 * on the audit trail but not on this.
 */
class IndexSecurityAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view-security-alerts');
    }

    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],

            // Validated against the enum for the same reason the audit log's
            // action filter is: an unrecognised type must not come back as "no
            // alerts", which reads like "nothing is wrong".
            'alert_type' => ['sometimes', 'string', 'in:'.implode(',', array_column(SecurityAlertType::cases(), 'value'))],

            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'alert_type.in' => 'Type d\'alerte inconnu. Consultez App\Enums\SecurityAlertType pour les valeurs acceptées.',
            'to.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Seul un administrateur peut consulter les alertes de sécurité.',
        ], 403));
    }
}
