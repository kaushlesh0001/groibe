@if (session()->has(\App\Actions\Admin\StartImpersonation::SESSION_KEY) && auth()->check())
    <div role="alert" class="flex items-center justify-center gap-3 bg-[#001844] px-4 py-2 text-sm text-white">
        <span>Impersonating {{ auth()->user()->name }} ({{ auth()->user()->email }})</span>
        <form method="POST" action="{{ route('impersonate.stop') }}">
            @csrf
            <button type="submit" class="rounded bg-[#14C352] px-3 py-1 font-medium text-[#001844] hover:opacity-90">Stop</button>
        </form>
    </div>
@endif
