{{-- SPEC.md 1.8's own disclosed gap: route()/view() written as literal PHP inside a Blade
     template, not a directive - regex could never see these, only compiling the template can. --}}
<a href="{{ route('orders.show') }}">View order</a>
{{ view('blade-compiler.other-view') }}
@php
    $greeter = new \App\Greeter();
    $greeter->hello();
@endphp
{{-- @error compiles to a call on Blade's own $__bag local, on top of $errors (auto-shared into
     every view by Illuminate\View\Middleware\ShareErrorsFromSession) - both framework/Blade
     runtime, never application code, so neither should ever show up in `unresolved`. --}}
@error('notes')
    <span>{{ $message }}</span>
@enderror
