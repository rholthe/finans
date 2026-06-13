import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './Root';

const el = document.getElementById('app');
if (el) {
    createRoot(el).render(
        <StrictMode>
            <App />
        </StrictMode>,
    );
}
