<?php

namespace App\Http\Controllers;

use App\Models\ForbiddenWord;
use App\Services\ActivityLogService;
use App\Services\Chat\ChatWordFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Settings → Forbidden Words: what the internal chat warns on.
 *
 * Held by 'manage chat moderation' — deliberately not 'monitor chats',
 * which grants full read access to every conversation. See the
 * permission's own migration for why they're kept separate.
 */
class ForbiddenWordController extends Controller
{
    public const PERMISSION = 'manage chat moderation';

    public function __construct(private readonly ActivityLogService $activityLog)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeManage($request);

        return view('settings.forbidden-words', [
            'words' => ForbiddenWord::ordered()->with('creator:id,name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $this->normalize($request);

        $data = $this->validated($request);

        $word = ForbiddenWord::create($data + ['is_active' => true, 'created_by' => Auth::id()]);

        ChatWordFilter::flushCache();
        $this->activityLog->log('Chat', 'Forbidden Word Added', null, null, ['word' => $word->word]);

        return response()->json(['success' => true, 'data' => $word->load('creator:id,name')]);
    }

    public function update(Request $request, ForbiddenWord $forbiddenWord): JsonResponse
    {
        $this->authorizeManage($request);
        $this->normalize($request);

        $data = $this->validated($request, $forbiddenWord);

        $before = $forbiddenWord->only(['word', 'is_active']);
        $forbiddenWord->update($data);

        ChatWordFilter::flushCache();
        $this->activityLog->log('Chat', 'Forbidden Word Updated', null, $before, $forbiddenWord->fresh()->only(['word', 'is_active']));

        return response()->json(['success' => true, 'data' => $forbiddenWord->fresh()->load('creator:id,name')]);
    }

    public function destroy(Request $request, ForbiddenWord $forbiddenWord): JsonResponse
    {
        $this->authorizeManage($request);

        $word = $forbiddenWord->word;
        $forbiddenWord->delete();

        ChatWordFilter::flushCache();
        $this->activityLog->log('Chat', 'Forbidden Word Removed', null, ['word' => $word], null);

        return response()->json(['success' => true, 'message' => "\"{$word}\" removed."]);
    }

    /**
     * Case-insensitive by design (see ChatWordFilter), so the stored form and
     * the uniqueness check both need to agree on one normalized form — done
     * before validation runs, not after, so a differently-cased duplicate is
     * caught as a validation error instead of a database exception.
     */
    private function normalize(Request $request): void
    {
        if ($request->filled('word')) {
            $request->merge(['word' => strtolower(trim((string) $request->input('word')))]);
        }
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?ForbiddenWord $word = null): array
    {
        $required = $word ? 'sometimes' : 'required';

        return $request->validate([
            'word'      => [$required, 'string', 'max:150', Rule::unique('forbidden_words', 'word')->ignore($word?->id)],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'word.unique' => 'That word or phrase is already on the list.',
        ]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can(self::PERMISSION), 403);
    }
}
