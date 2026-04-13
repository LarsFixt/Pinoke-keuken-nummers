<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\Component;
use App\Models\User;

new #[Title('Users')] class extends Component {
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')->orWhere('email', 'like', '%' . $this->search . '%');
            })
            ->orderBy('name')
            ->paginate(10);
    }

    public function toggleAdmin(int $userId): void
    {
        if ($userId === auth()->id()) {
            return;
        }

        $user = User::findOrFail($userId);

        if ($user->is_super_admin) {
            return;
        }

        $user->is_admin = !$user->is_admin;
        $user->save();

        unset($this->users);
    }
};
?>

<div title="Users">
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <flux:heading size="xl" level="1">{{ __('Manage Users') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('Assign admin roles to dashboard users') }}</flux:subheading>
        <flux:separator variant="subtle" />

        <div class="my-6 w-full space-y-6">
            <div class="flex items-center gap-4">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                    :placeholder="__('Search users...')" class="max-w-md" />
            </div>

            <div class="space-y-4">
                @foreach ($this->users as $user)
                    <div wire:key="user-{{ $user->id }}"
                        class="flex items-center justify-between gap-4 p-4 rounded-lg bg-white dark:bg-zinc-900 border border-gray-200 dark:border-zinc-800 shadow-sm">
                        <div class="flex flex-col">
                            <span class="font-medium dark:text-zinc-100 text-gray-900">{{ $user->name }}</span>
                            <span class="text-sm dark:text-zinc-400 text-gray-500">{{ $user->email }}</span>
                        </div>
                        <div>
                            @if ($user->id === auth()->id())
                                <flux:badge color="zinc">{{ __('You') }}</flux:badge>
                            @elseif ($user->is_super_admin)
                                <flux:badge color="zinc">{{ __('Super Admin') }}</flux:badge>
                            @else
                                <flux:switch wire:click="toggleAdmin({{ $user->id }})" :checked="$user->is_admin" />
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                {{ $this->users->links() }}
            </div>
        </div>
    </div>
</div>
