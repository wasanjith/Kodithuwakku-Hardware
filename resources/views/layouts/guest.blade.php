<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-brand-950">
<head>
    @include('layouts.partials.head')
</head>
<body class="relative flex min-h-full items-center justify-center overflow-hidden bg-linear-to-br from-brand-950 via-brand-900 to-brand-700 px-4 py-10 font-sans text-gray-900 antialiased">
    {{-- Soft background glow --}}
    <div class="pointer-events-none absolute -top-32 -right-32 size-96 rounded-full bg-brand-500/30 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-40 -left-32 size-96 rounded-full bg-amber-400/10 blur-3xl" aria-hidden="true"></div>

    <div class="relative w-full @yield('width', 'max-w-sm')">
        <div class="mb-6 text-center">
            <img src="{{ asset('images/logo.svg') }}" alt="Kodithuwakku Hardware" class="mx-auto h-24 w-auto">
            <p class="mt-3 text-xs font-semibold tracking-[0.3em] text-amber-300 uppercase">Point of Sale</p>
        </div>
        <div class="rounded-2xl border-t-4 border-amber-400 bg-white p-6 shadow-2xl shadow-brand-950/60 sm:p-8">
            @yield('content')
        </div>
        @hasSection('below')
            <div class="mt-4 text-center text-sm text-brand-100">@yield('below')</div>
        @endif
        <p class="mt-6 text-center text-xs text-brand-300">&copy; {{ now()->year }} Kodithuwakku Hardware</p>
    </div>
    @stack('scripts')
</body>
</html>
