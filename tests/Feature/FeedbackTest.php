<?php

use App\Models\Feedback;
use App\Models\Order;
use App\Models\User;
use App\NotificationTiming;
use App\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('asks for feedback once the tracked order is ready', function () {
    Order::factory()->create(['number' => '0042', 'status' => OrderStatus::Ready]);

    Livewire::withQueryParams(['order' => '0042'])
        ->test('pages::track')
        ->assertSee(__('How was ordering and waiting today?'))
        ->assertSee(__('Did we let you know in time that your order was ready?'));
});

it('does not ask for feedback while the order is still being prepared', function () {
    Order::factory()->create(['number' => '0042', 'status' => OrderStatus::Pending]);

    Livewire::withQueryParams(['order' => '0042'])
        ->test('pages::track')
        ->assertDontSee(__('How was ordering and waiting today?'));
});

it('stores every answer on the same feedback record with its context', function () {
    $order = Order::factory()->create(['number' => '0042', 'status' => OrderStatus::Ready]);
    $context = ['wait_minutes' => 12, 'notification_mode' => 'push-ready', 'platform' => 'android', 'display_mode' => 'browser', 'entry' => 'qr'];

    Livewire::withQueryParams(['order' => '0042'])
        ->test('pages::track')
        ->call('saveFeedback', ['rating' => 4], $context)
        ->call('saveFeedback', ['notification_timing' => 'late'], $context)
        ->call('saveFeedback', ['comment' => '  Bell was hard to hear  '], $context)
        ->assertSet('feedbackRating', 4)
        ->assertSet('feedbackTiming', 'late');

    expect(Feedback::sole())
        ->order_id->toBe($order->id)
        ->rating->toBe(4)
        ->notification_timing->toBe(NotificationTiming::Late)
        ->comment->toBe('Bell was hard to hear')
        ->wait_minutes->toBe(12)
        ->notification_mode->toBe('push-ready')
        ->platform->toBe('android')
        ->entry->toBe('qr');
});

it('starts a new feedback record after tracking another order', function () {
    Order::factory()->create(['number' => '0042', 'status' => OrderStatus::Ready]);
    Order::factory()->create(['number' => '0043', 'status' => OrderStatus::Ready]);

    Livewire::withQueryParams(['order' => '0042'])
        ->test('pages::track')
        ->call('saveFeedback', ['rating' => 5])
        ->call('stopTracking')
        ->assertSet('feedbackRating', null)
        ->call('startWatching', '0043')
        ->call('saveFeedback', ['rating' => 2]);

    expect(Feedback::with('order')->orderBy('id')->get()->map(fn (Feedback $feedback) => [$feedback->order->number, $feedback->rating])->all())
        ->toBe([['0042', 5], ['0043', 2]]);
});

it('ignores feedback when the order is not ready', function () {
    Order::factory()->create(['number' => '0042', 'status' => OrderStatus::Pending]);

    Livewire::withQueryParams(['order' => '0042'])
        ->test('pages::track')
        ->call('saveFeedback', ['rating' => 5]);

    expect(Feedback::count())->toBe(0);
});

it('rejects invalid feedback answers', function (array $answers, string $field) {
    Order::factory()->create(['number' => '0042', 'status' => OrderStatus::Ready]);

    Livewire::withQueryParams(['order' => '0042'])
        ->test('pages::track')
        ->call('saveFeedback', $answers)
        ->assertHasErrors($field);

    expect(Feedback::count())->toBe(0);
})->with([
    'rating too high' => [['rating' => 6], 'rating'],
    'unknown timing' => [['notification_timing' => 'sometimes'], 'notification_timing'],
    'comment too long' => [['comment' => str_repeat('a', 281)], 'comment'],
]);

it('shows the feedback overview with a summary to admins', function () {
    Feedback::factory()->for(Order::factory()->state(['number' => '0042']))->create(['rating' => 5, 'notification_timing' => NotificationTiming::OnTime, 'comment' => 'Super snel!']);
    Feedback::factory()->create(['rating' => 2, 'notification_timing' => NotificationTiming::Missed]);
    Feedback::factory()->create(['rating' => 4, 'notification_timing' => NotificationTiming::Late, 'created_at' => now()->subDays(10)]);

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test('pages::feedback')
        ->assertSeeInOrder([__('Responses'), '2', __('Average rating'), '3.5', __('Notified in time'), '50%', __('Late or missed'), '50%'])
        ->assertSee('#0042')
        ->assertSee('Super snel!')
        ->set('period', 'all')
        ->assertSeeInOrder([__('Responses'), '3']);
});

it('only lets admins open the feedback overview', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get(route('feedback'))
        ->assertForbidden();

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('feedback'))
        ->assertOk();
});
