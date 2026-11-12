/**
 * Dialogo informativo sobre administracion de usuarios autorizados en la API externa.
 */
import { useEffect } from 'react';
import Tokens from './Tokens';

export default function Usuarios({ sesion, onClose }) {
  const admin = sesion.rol === 'admin';

  useEffect(() => {
    const cerrarConEscape = (e) => e.key === 'Escape' && onClose();
    addEventListener('keydown', cerrarConEscape);
    return () => removeEventListener('keydown', cerrarConEscape);
  }, [onClose]);

  return (
    <>
      <div className="ov" onClick={onClose} />
      <aside role="dialog" aria-label={admin ? 'Gestión de tokens' : 'Mi cuenta'}>
        <button className="x" onClick={onClose} aria-label="Cerrar">Cerrar</button>
        <h2>{admin ? 'Administración de tokens' : 'Mi cuenta'}</h2>
        {admin ? <Tokens /> : <p>Sesión iniciada como <b>{sesion.nombre || sesion.usuario}</b>.</p>}
      </aside>
    </>
  );
}
