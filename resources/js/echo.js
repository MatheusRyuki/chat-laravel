import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const chave = import.meta.env.VITE_PUSHER_APP_KEY;
const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER;
const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

export function obterEcho() {
    if (window.Echo) {
        return window.Echo;
    }

    if (! chave || ! cluster) {
        console.info(
            'Pusher não configurado: preencha PUSHER_APP_KEY e PUSHER_APP_CLUSTER no .env e execute ./vendor/bin/sail npm run build.',
        );

        return null;
    }

    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: chave,
        cluster,
        forceTLS: true,
        auth: {
            headers: {
                'X-CSRF-TOKEN': token ?? '',
            },
        },
    });

    return window.Echo;
}
