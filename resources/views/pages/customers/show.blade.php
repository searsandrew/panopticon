<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new class extends Component {
    public string $customer = '';

    public function mount(string $customer): void
    {
        $this->customer = $customer;
    }

    public function render()
    {
        return $this->view()
            ->title($this->customer);
    }
};
?>

<section class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl">{{ __('Customer') }} {{ $customer }}</flux:heading>
        <flux:text>{{ __('Communication') }}</flux:text>
    </div>
</section>
