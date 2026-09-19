<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Confirmar remoção
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl space-y-4">
                    <p class="text-sm text-gray-600">
                        Esta mensagem será removida para os participantes da conversa.
                    </p>

                    <div class="rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-800">
                        @if ($mensagem->conteudo !== '')
                            <p class="whitespace-pre-wrap">{{ $mensagem->conteudo }}</p>
                        @endif
                        @if ($mensagem->temAnexo())
                            <p @class(['mt-2' => $mensagem->conteudo !== ''])>Imagem</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-3">
                        <form method="POST" action="{{ route('mensagens.destroy', $mensagem) }}">
                            @csrf
                            @method('DELETE')
                            <x-danger-button>Confirmar remoção</x-danger-button>
                        </form>

                        <a href="{{ $voltarUrl }}" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            Cancelar
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
