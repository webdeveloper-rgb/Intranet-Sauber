/**
 * Dialogo informativo sobre administracion de usuarios autorizados en la API externa.
 */
import { useEffect } from 'react';
import Tokens from './Tokens';
import PageHeading from '../../../shared/ui/PageHeading';

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
      <aside role="dialog" aria-modal="true" aria-label={admin ? 'Gestión de tokens' : 'Mi cuenta'}>
        <PageHeading level={2} eyebrow={admin ? 'Administración' : 'Cuenta'}
          title={admin ? 'Tokens de integración' : 'Mi cuenta'}
          actions={<button className="x" onClick={onClose} aria-label="Cerrar">×</button>} />
        {admin ? <Tokens /> : <p>Sesión iniciada como <b>{sesion.nombre || sesion.usuario}</b>.</p>}
      </aside>
    </>
  );
}
