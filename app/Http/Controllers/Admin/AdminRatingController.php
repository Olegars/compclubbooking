<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminRatingEvent;
use App\Services\AdminRatingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

class AdminRatingController extends Controller
{
    public function __construct(private readonly AdminRatingService $rating)
    {
    }

    public function index()
    {
        return Inertia::render('Admin/Rating', $this->rating->page());
    }

    public function store(Request $request)
    {
        $data = $this->validateEvent($request);

        try {
            $this->rating->create($data['name'], (int) $data['points'], $data['direction']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('success', 'Событие добавлено');
    }

    public function update(Request $request, AdminRatingEvent $event)
    {
        $data = $this->validateEvent($request);

        try {
            $this->rating->update($event, $data['name'], (int) $data['points'], $data['direction']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('success', 'Событие сохранено');
    }

    public function destroy(AdminRatingEvent $event)
    {
        $this->rating->delete($event);

        return back()->with('success', 'Событие удалено');
    }

    public function apply(Request $request)
    {
        $data = $request->validate([
            'admin_id' => ['required', 'integer', 'exists:admins,id'],
            'event_id' => ['required', 'integer', 'exists:admin_rating_events,id'],
        ]);

        $actor = auth('admin')->user();
        $target = Admin::query()->findOrFail($data['admin_id']);
        $event = AdminRatingEvent::query()->findOrFail($data['event_id']);

        try {
            $entry = $this->rating->apply($actor, $target, $event);
        } catch (RuntimeException $e) {
            return back()->withErrors(['apply' => $e->getMessage()]);
        }

        $sign = $entry->points > 0 ? '+' : '';

        return back()->with('success', "{$target->name}: {$entry->event_name} {$sign}{$entry->points}");
    }

    /**
     * @return array{name:string,points:int,direction:string}
     */
    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'points' => ['required', 'integer', 'min:1', 'max:100000'],
            'direction' => ['required', Rule::in([AdminRatingEvent::ADD, AdminRatingEvent::SUBTRACT])],
        ], [
            'name.required' => 'Укажите название события.',
            'points.required' => 'Укажите количество баллов.',
            'points.min' => 'Баллов должно быть хотя бы 1.',
            'direction.in' => 'Укажите, начислять или снимать баллы.',
        ]);
    }
}
