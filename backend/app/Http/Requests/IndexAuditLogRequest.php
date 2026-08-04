<?php

namespace App\Http\Requests;

use App\Enums\AuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Authorization and filter validation for GET /v1/audit-logs (§10.4).
 *
 * Same shape as ProcessArticleAlertRequest: the Gate check lives here so the
 * denial keeps an accurate French message instead of Laravel's generic English
 * default, which the Angular client surfaces verbatim.
 */
class IndexAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view-audit-logs');
    }

    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],

            /**
             * Validated against the enum rather than left free-form: a typo in
             * a filter otherwise returns an empty page, which reads exactly
             * like "this user did nothing" — the most dangerous wrong answer a
             * security log can give. Historical rows whose action has since
             * left the enum stay readable, they simply cannot be filtered for
             * by name; the date and user filters still reach them.
             */
            'action' => ['sometimes', 'string', 'in:'.implode(',', array_column(AuditAction::cases(), 'value'))],

            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],

            // Accepted, and can only ever narrow the result within the caller's
            // own filiale — see AuditLogController::index().
            'filiale_id' => ['sometimes', 'uuid'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.in' => 'Action inconnue. Consultez App\Enums\AuditAction pour les valeurs acceptées.',
            'to.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Seul un administrateur ou un propriétaire des données peut consulter le journal d\'audit.',
        ], 403));
    }
}
