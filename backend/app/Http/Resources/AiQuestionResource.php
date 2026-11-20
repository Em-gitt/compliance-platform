<?php

namespace App\Http\Resources;

use App\Models\AiQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiQuestion
 */
class AiQuestionResource extends JsonResource
{
    private ?string $disclaimer = null;

    /**
     * The disclaimer comes from the AI service (CMP-31) and is not stored in ai_questions.
     */
    public function withDisclaimer(?string $disclaimer): self
    {
        $this->disclaimer = $disclaimer;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userFeedback = null;
        if ($request->user()) {
            if ($this->relationLoaded('feedback')) {
                $userFeedback = $this->feedback->firstWhere('user_id', $request->user()->id);
            } else {
                $userFeedback = $this->feedback()->where('user_id', $request->user()->id)->first();
            }
        }

        return [
            'id' => $this->id,
            'question' => $this->question,
            'answer' => $this->answer,
            'status' => $this->status,
            'citations' => $this->citations ?? [],
            'disclaimer' => $this->disclaimer,
            'latency_ms' => $this->latency_ms,
            'created_at' => $this->created_at?->toIso8601String(),
            'feedback' => $userFeedback ? [
                'rating' => $userFeedback->rating,
                'comment' => $userFeedback->comment,
            ] : null,
        ];
    }
}
