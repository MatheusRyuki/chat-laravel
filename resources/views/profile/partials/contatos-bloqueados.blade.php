<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            Contatos bloqueados
        </h2>
        <p class="mt-1 text-sm text-gray-600">
            Somente quem criou o bloqueio pode removê-lo. O histórico anterior permanece visível.
            O bloqueio individual não oculta mensagens em grupos compartilhados.
        </p>
    </header>

    @if (session('status') && ! in_array(session('status'), ['profile-updated', 'password-updated'], true))
        <p class="mt-4 text-sm text-gray-700">{{ session('status') }}</p>
    @endif

    @if ($bloqueios->isEmpty())
        <p class="mt-4 text-sm text-gray-600">Nenhum contato bloqueado.</p>
    @else
        <ul class="mt-4 space-y-3">
            @foreach ($bloqueios as $bloqueio)
                <li class="flex items-center justify-between gap-4">
                    <span>{{ $bloqueio->bloqueado?->name }} <span class="text-gray-500">({{ $bloqueio->bloqueado?->email }})</span></span>
                    <form method="POST" action="{{ route('bloqueios.destroy', $bloqueio) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-indigo-600 hover:underline">Desbloquear</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</section>
