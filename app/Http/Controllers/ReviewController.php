<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\User;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    /** Functional teams staff can leave department-level feedback about. */
    private const DEPARTMENTS = ['Sales', 'Document', 'Design', 'Website', 'Product', 'Marketing', 'Support', 'Accounts', 'Content'];

    public function __construct(
        private readonly ReviewService $service,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax()) {
            abort_unless(Auth::user()->can('view reviews'), 403);

            $reviews = Review::with(['subjectUser:id,name', 'poster:id,name'])
                ->latest()
                ->get()
                ->map(fn (Review $r) => $this->resource($r));

            return response()->json(['data' => $reviews]);
        }

        $users = User::where('is_active', true)
            ->where('id', '!=', Auth::id())
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('reviews.index', ['users' => $users, 'departments' => self::DEPARTMENTS]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'               => ['required', Rule::in(Review::$types)],
            'subject_type'       => ['required', Rule::in(['general', 'user', 'department'])],
            'subject_user_id'    => ['nullable', 'required_if:subject_type,user', 'exists:users,id'],
            'subject_department' => ['nullable', 'required_if:subject_type,department', 'string', 'max:60'],
            'title'              => ['required', 'string', 'max:255'],
            'message'            => ['required', 'string', 'max:5000'],
            'is_anonymous'       => ['boolean'],
        ]);

        if ($data['subject_type'] !== 'user') {
            $data['subject_user_id'] = null;
        }
        if ($data['subject_type'] !== 'department') {
            $data['subject_department'] = null;
        }

        $review = $this->service->create($data, Auth::user());

        return response()->json(['success' => true, 'poster_token' => $review->poster_token]);
    }

    /**
     * Reviews the caller's own browser is tracking by token — works for
     * anonymous reviews too, since that's the only record of "this is mine".
     */
    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate(['tokens' => ['array'], 'tokens.*' => ['string']]);

        $reviews = $this->service->findByTokens($data['tokens'] ?? [])
            ->map(fn (Review $r) => $this->resource($r, revealSelf: true));

        return response()->json(['data' => $reviews]);
    }

    public function destroy(Review $review): JsonResponse
    {
        abort_unless(Auth::user()->can('view reviews'), 403);

        $review->delete();

        return response()->json(['success' => true]);
    }

    private function resource(Review $r, bool $revealSelf = false): array
    {
        return [
            'id'                 => $r->id,
            'type'               => $r->type,
            'title'              => $r->title,
            'message'            => $r->message,
            'is_anonymous'       => $r->is_anonymous,
            'is_mine'            => $revealSelf,
            'poster_token'       => $revealSelf ? $r->poster_token : null,
            'subject_user_name'  => $r->subjectUser?->name,
            'subject_department' => $r->subject_department,
            'posted_by_name'     => $r->is_anonymous ? null : $r->poster?->name,
            'created_at_human'   => $r->created_at->diffForHumans(),
            'created_at'         => $r->created_at->format('d M Y, h:i A'),
        ];
    }
}
