<x-pulse full-width>
    <livewire:pulse.servers cols="full" ignore-after="3 hours" />

    <livewire:pulse.usage type="requests" cols="4" rows="2" />

    <livewire:pulse.usage type="slow_requests" cols="4" rows="2" />

    <livewire:pulse.usage type="jobs" cols="4" rows="2" />

    <livewire:pulse.queues cols="4" />

    <livewire:pulse.cache cols="4" />

    <livewire:pulse.exceptions cols="4" />

    <livewire:pulse.slow-queries cols="6" />

    <livewire:pulse.slow-requests cols="6" />

    <livewire:pulse.slow-jobs cols="6" />

    <livewire:pulse.slow-outgoing-requests cols="6" />
</x-pulse>
