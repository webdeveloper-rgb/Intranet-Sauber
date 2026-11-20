import { useState, useEffect } from 'react';
import { api } from '../../../shared/api/client';

export default function RustDeskAcceso({ equipo, monitor, admin }) {
  const [cargandoPassword, setCargandoPassword] = useState(false);
  const [copiada, setCopiada] = useState(false);
  const [error, setError] = useState('');
  const rustDeskId = monitor?.rustDeskId || '';

  useEffect(() => {
    setCopiada(false);
    setError('');
  }, [equipo.id, rustDeskId]);

  const copiarPassword = async () => {
    setCargandoPassword(true);
    setCopiada(false);
    setError('');
    try {
      const result = await api('equipo_rustdesk_password', { id: equipo.id });
      if (!result.password) throw new Error('Este equipo aún no ha enviado una contraseña. Vuelve a ejecutar el instalador actualizado en ese equipo.');
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(result.password);
      } else {
        const campo = document.createElement('textarea');
        campo.value = result.password;
        campo.setAttribute('readonly', '');
        campo.style.position = 'fixed';
        campo.style.opacity = '0';
        document.body.appendChild(campo);
        campo.select();
        let copiadaAlPortapapeles = false;
        try { copiadaAlPortapapeles = document.execCommand('copy'); }
        finally { campo.remove(); }
        if (!copiadaAlPortapapeles) throw new Error('El navegador no permitió copiar la contraseña.');
      }
      setCopiada(true);
      window.setTimeout(() => setCopiada(false), 3000);
    } catch (e) {
      setError(e.message || 'No se pudo copiar la contraseña.');
    } finally {
      setCargandoPassword(false);
    }
  };

  return <div className="equipo-rustdesk-acceso">
    <div className="equipo-rustdesk-id">
      <span>ID de RustDesk</span>
      <code>{rustDeskId || 'Aún no recibido'}</code>
    </div>
    <div className="equipo-rustdesk-acciones">
      {rustDeskId && <a className="btn rustdesk-conectar" href={`rustdesk://connection/new/${encodeURIComponent(rustDeskId)}`}>
        Conectar con RustDesk
      </a>}
      {admin && <button type="button" className="btn sec equipo-rustdesk-copiar"
        onClick={copiarPassword} disabled={cargandoPassword}>
        {cargandoPassword ? 'Copiando…' : copiada ? 'Contraseña copiada' : 'Copiar contraseña permanente'}
      </button>}
    </div>
    {error && <span className="equipo-rustdesk-error" role="alert">{error}</span>}
  </div>;
}

