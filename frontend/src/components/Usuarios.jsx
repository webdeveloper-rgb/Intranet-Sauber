import { useState, useEffect, useCallback } from 'react';
import { api } from '../api';
import Paginacion from './Paginacion';
import { usePagination } from '../hooks/usePagination';

function FilaUsuario({ u, yo, onCambio }) {
  const propio = u.usuario === yo;
  const editar = async (campos) => {
    try {
      await api('usuario_editar', { id: u.id, ...campos });
      onCambio();
    } catch (e) {
      alert(e.message);
    }
  };

  return (
    <div className={'urow' + (u.activo ? '' : ' off')}>
      <div className="uinfo">
        <b>{u.nombre || u.usuario}</b>{propio && <span className="nb"> tú</span>}
        {!u.activo && <span className="nb al">Acceso desactivado</span>}
        <small>{u.usuario}</small>
      </div>
      <select value={u.rol} disabled={propio} aria-label={'Rol de ' + u.usuario}
        onChange={(e) => editar({ rol: e.target.value })}>
        <option value="usuario">Técnico</option>
        <option value="admin">Administrador</option>
      </select>
      {!propio && (
        <button className="btn sec" onClick={() => editar({ activo: !u.activo })}>
          {u.activo ? 'Desactivar acceso' : 'Activar acceso'}
        </button>
      )}
    </div>
  );
}

export default function Usuarios({ sesion, onClose }) {
  const admin = sesion.rol === 'admin';
  const [lista, setLista] = useState([]);
  const [fallo, setFallo] = useState('');
  const pagina = usePagination(lista, 20);

  const cargar = useCallback(async () => {
    if (!admin) return;
    try {
      setLista((await api('usuarios')).usuarios);
      setFallo('');
    } catch (e) {
      setFallo(e.message);
    }
  }, [admin]);
  useEffect(() => { cargar(); }, [cargar]);

  useEffect(() => {
    const k = (e) => e.key === 'Escape' && onClose();
    addEventListener('keydown', k);
    return () => removeEventListener('keydown', k);
  }, [onClose]);

  return (
    <>
      <div className="ov" onClick={onClose} />
      <aside role="dialog" aria-label={admin ? 'Gestión de accesos' : 'Mi cuenta'}>
        <button className="x" onClick={onClose} aria-label="Cerrar">Cerrar</button>
        <h2>{admin ? 'Gestión de accesos' : 'Mi cuenta'}</h2>
        <p className="mut">El acceso se valida con las credenciales personales de la API y la lista autorizada de la tabla Usuarios. Las contraseñas no se almacenan en este panel.</p>
        {!admin && <p>Sesión iniciada como <b>{sesion.nombre || sesion.usuario}</b>.</p>}
        {admin && (
          <section className="ucard">
            <h3>Perfiles que ya han accedido ({lista.length})</h3>
            <p className="mut">Aquí puedes ajustar el rol del panel o bloquear el acceso local. La API determina quién puede iniciar sesión.</p>
            {fallo && <div className="errbox">{fallo}</div>}
            {pagina.items.map((u) => <FilaUsuario key={u.id} u={u} yo={sesion.usuario} onCambio={cargar} />)}
            {!fallo && lista.length === 0 && <p className="mut">Los perfiles aparecerán cuando una persona autorizada inicie sesión por primera vez.</p>}
            <Paginacion total={lista.length} pagina={pagina.page} paginas={pagina.pageCount}
              porPagina={pagina.pageSize} onCambiar={pagina.setPage} etiqueta="usuarios" />
          </section>
        )}
      </aside>
    </>
  );
}
