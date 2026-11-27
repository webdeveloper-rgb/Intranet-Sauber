/**
 * Punto de montaje de la aplicacion React en el elemento root de index.php.
 */
import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './app/App';
import { ToastProvider } from './shared/components/Toast.jsx';
import '@fortawesome/fontawesome-free/css/fontawesome.css';
import '@fortawesome/fontawesome-free/css/solid.css';
import './styles/index.css';
import './shared/ui/tokens.css';

createRoot(document.getElementById('root')).render(
  <ToastProvider><App /></ToastProvider>
);
