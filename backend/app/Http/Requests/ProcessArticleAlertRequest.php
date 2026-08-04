<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared authorization for the two Process Owner transitions —
 * POST /v1/alerts/{alert}/acknowledge and /close.
 *
 * Neither takes a body, so this request exists purely to move the Gate check
 * out of the controller and to give the denial an accurate French message,
 * the same job RejectArticleRequest::failedAuthorization() does on the article
 * side. The state check (ouverte -> en_cours -> cloturee) deliberately stays
 * in the controller: a wrong-state call is a 422 about the workflow, not a 403
 * about who you are, and conflating them makes both harder to act on.
 */
class ProcessArticleAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('process-article-alerts');
    }

    public function rules(): array
    {
        return [];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Seul un propriétaire des données ou un administrateur peut traiter un signalement.',
        ], 403));
    }
}
