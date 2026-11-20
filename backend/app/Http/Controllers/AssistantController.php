<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AskQuestionRequest;
use App\Http\Resources\AiQuestionResource;
use App\Models\AiFeedback;
use App\Models\AiQuestion;
use App\Services\AiServiceClient;
use App\Services\AllowedDocumentResolver;
use App\Services\Exceptions\AiServiceException;
use App\Services\Exceptions\AiServiceLlmTimeoutException;
use App\Support\AssistantMessages;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class AssistantController extends Controller
{
    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly AiServiceClient $ai,
        private readonly AllowedDocumentResolver $allowedDocuments,
    ) {}

    /**
     * POST /api/assistant/questions (FR-010, AIR-001).
     * Calls FastAPI /v1/ask (15 s timeout, CMP-07) and stores every attempt in ai_questions.
     */
    public function ask(AskQuestionRequest $request): JsonResponse
    {
        $user = $request->user();
        $question = $request->validated('question');
        $started = hrtime(true);

        try {
            $result = $this->ai->ask(
                question: $question,
                userId: $user->id,
                organizationId: $user->organization_id,
                allowedDocumentIds: $this->allowedDocuments->forUser($user),
                requestId: (string) Str::uuid(),
            );
        } catch (ConnectionException $exception) {
            $timedOut = str_contains($exception->getMessage(), 'cURL error 28') || str_contains(strtolower($exception->getMessage()), 'timed out');

            return $this->failure($user->id, $question, $started, $timedOut);
        } catch (AiServiceLlmTimeoutException) {
            return $this->failure($user->id, $question, $started, timedOut: true);
        } catch (AiServiceException) {
            // Service down, provider not authorized (CMP-28) or any other AI service error.
            return $this->failure($user->id, $question, $started, timedOut: false);
        }

        $record = AiQuestion::query()->create([
            'user_id' => $user->id,
            'question' => $question,
            'answer' => (string) ($result['answer'] ?? ''),
            'status' => (string) ($result['status'] ?? 'answered'),
            'citations' => array_values($result['citations'] ?? []),
            'latency_ms' => $this->elapsedMs($started),
        ]);

        return (new AiQuestionResource($record))
            ->withDisclaimer($result['disclaimer'] ?? null)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/assistant/questions: last 50 questions of the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $questions = AiQuestion::query()
            ->with('feedback')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return AiQuestionResource::collection($questions);
    }

    /**
     * POST /api/assistant/questions/{id}/feedback (CMP-35 / FR-019).
     */
    public function feedback(Request $request, int $id): JsonResponse
    {
        $question = AiQuestion::find($id);

        if (! $question) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        if ($question->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only rate your own answers.'], 403);
        }

        $rating = $request->input('rating');
        if (! in_array($rating, ['useful', 'not_useful'], true)) {
            return response()->json([
                'message' => 'Select Useful or Not useful.',
                'errors' => ['rating' => ['Select Useful or Not useful.']],
            ], 422);
        }

        $comment = $request->input('comment');
        if ($comment !== null && mb_strlen($comment) > 500) {
            return response()->json([
                'message' => 'Comments can be up to 500 characters.',
                'errors' => ['comment' => ['Comments can be up to 500 characters.']],
            ], 422);
        }

        $feedback = AiFeedback::updateOrCreate(
            [
                'ai_question_id' => $question->id,
                'user_id' => $request->user()->id,
            ],
            [
                'rating' => $rating,
                'comment' => $comment,
            ]
        );

        return response()->json([
            'message' => 'Thanks for your feedback.',
            'feedback' => $feedback,
        ], 200);
    }

    /**
     * GET /api/admin/ai-feedback (CMP-35).
     */
    public function adminFeedbackIndex(Request $request): mixed
    {
        if ($request->user()->role !== Role::Administrator) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($request->query('export') === 'csv' || $request->header('Accept') === 'text/csv') {
            return $this->exportFeedback($request);
        }

        $feedbacks = AiFeedback::query()
            ->with(['user:id,name,email', 'question:id,question,answer'])
            ->latest('id')
            ->paginate(50);

        return response()->json($feedbacks);
    }

    /**
     * GET /api/admin/ai-feedback/export (CMP-35).
     */
    public function exportFeedback(Request $request): mixed
    {
        if ($request->user()->role !== Role::Administrator) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $feedbacks = AiFeedback::query()
            ->with(['user', 'question'])
            ->latest('id')
            ->get();

        $csv = "id,user_id,user_name,user_email,ai_question_id,question,rating,comment,created_at\n";
        foreach ($feedbacks as $item) {
            $csv .= sprintf(
                "%d,%d,\"%s\",\"%s\",%d,\"%s\",\"%s\",\"%s\",\"%s\"\n",
                $item->id,
                $item->user_id,
                str_replace('"', '""', $item->user?->name ?? ''),
                str_replace('"', '""', $item->user?->email ?? ''),
                $item->ai_question_id,
                str_replace('"', '""', $item->question?->question ?? ''),
                $item->rating,
                str_replace('"', '""', $item->comment ?? ''),
                $item->created_at?->toIso8601String() ?? ''
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="ai-feedback.csv"',
        ]);
    }

    private function failure(int $userId, string $question, int $started, bool $timedOut): JsonResponse
    {
        AiQuestion::query()->create([
            'user_id' => $userId,
            'question' => $question,
            'answer' => '',
            'status' => $timedOut ? self::STATUS_TIMEOUT : self::STATUS_UNAVAILABLE,
            'citations' => [],
            'latency_ms' => $this->elapsedMs($started),
        ]);

        return $timedOut
            ? response()->json(['message' => AssistantMessages::TIMEOUT], 504)
            : response()->json(['message' => AssistantMessages::UNAVAILABLE], 503);
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
