import { useState, useEffect, useCallback } from 'react';
import { api } from '../api';

const MIN_PASS = 10;

function MiPassword() {
  const [f, setF] = useState({ actual: '', nueva: '' });
  const [msg, setMsg] = useState(null);
  const [busy, setBusy] = useState(false);
  const ok = f.actual && f.nueva.length >= MIN_PASS;

  const guardar = async () => {
    setBusy(true);
    try {
      await api('mi_password', f);
      setF({ actual: '', nueva: '' });
      setMsg({ ok: true, t: 'Contraseña cambiada.' });
    } catch (e) {
      setMsg({ ok: false, t: e.message });
    }
    setBusy(false);
  };
  return (
    <section className="ucard">
      <h3>Cambiar mi contraseña</h3>
      <div className="uform">
        <input type="password" placeholder="Contraseña actual" autoComplete="current-password"
          value={f.actual} onChange={(e) => setF({ ...f, actual: e.target.value })} />
        <input type="password" placeholder={`Nueva (mín. ${MIN_PASS} caracteres)`} autoComplete="new-password"
          value={f.nueva} onChange={(e) => setF({ ...f, nueva: e.target.value })} />
        <button className="btn" disabled={!ok || busy} onClick={guardar}>Guardar</button>
      </div>
      {msg && <div className={msg.ok ? 'okmsg' : 'errbox'}>{msg.t}</div>}
    </section>
  );
}

function NuevoUsuario({ onCreado }) {
  const v0 = { usuario: '', nombre: '', password: '', rol: 'usuario' };
  const [f, setF] = useState(v0);
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  const ok = f.usuario.length >= 3 && f.password.length >= MIN_PASS;

  const crear = async () => {
    setBusy(true);
    try {
      await api('usuario_crear', f);
      setF(v0);
      setErr('');
      onCreado();
    } catch (e) {
      setErr(e.message);
    }
    setBusy(false);
  };
  return (
    <section className="ucard">
      <h3>Nuevo usuario</h3>
      <div className="uform">
        <input placeholder="Usuario (para entrar)" autoComplete="off" value={f.usuario} onChange={set('usuario')} />
        <input placeholder="Nombre (opcional)" autoComplete="off" value={f.nombre} onChange={set('nombre')} />
        <input type="password" placeholder={`Contraseña (mín. ${MIN_PASS})`} autoComplete="new-password"
          value={f.password} onChange={set('password')} />
        <select value={f.rol} onChange={set('rol')} aria-label="Rol">
          <option value="usuario">Técnico (revisa copias)</option>
          <option value="admin">Administrador (gestiona usuarios)</option>
        </select>
        <button className="btn" disabled={!ok || busy} onClick={crear}>Crear usuario</button>
      </div>
      {err && <div className="errbox">{err}</div>}
    </section>
  );
}

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
  const nuevaPass = () => {
    const p = prompt(`Nueva contraseña para "${u.usuario}" (mínimo ${MIN_PASS} caracteres):`);
    if (p) editar({ password: p });
  };
  return (
    <div className={'urow' + (u.activo ? '' : ' off')}>
      <div className="uinfo">
        <b>{u.usuario}</b>{propio && <span className="nb"> tú</span>}
        {!u.activo && <span className="nb al">Desactivado</span>}
        <small>{u.nombre !== u.usuario ? u.nombre + ' · ' : ''}creado {u.creado}</small>
      </div>
      <select value={u.rol} disabled={propio} aria-label={'Rol de ' + u.usuario}
        onChange={(e) => editar({ rol: e.target.value })}>
        <option value="usuario">Técnico</option>
        <option value="admin">Administrador</option>
      </select>
      <button className="btn sec" onClick={nuevaPass}>Nueva contraseña</button>
      {!propio && (
        <button className="btn sec" onClick={() => editar({ activo: !u.activo })}>
          {u.activo ? 'Desactivar' : 'Activar'}
        </button>
      )}
    </div>
  );
}

export default function Usuarios({ sesion, onClose }) {
  const admin = sesion.rol === 'admin';
  const [lista, setLista] = useState([]);
  const [fallo, setFallo] = useState('');

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
      <aside role="dialog" aria-label="Usuarios">
        <button className="x" onClick={onClose} aria-label="Cerrar">×</button>
        <h2>{admin ? 'Usuarios' : 'Mi cuenta'}</h2>
        <p className="mut">Sesión iniciada como <b>{sesion.usuario}</b>{admin ? ' (administrador)' : ''}.</p>
        {admin && <NuevoUsuario onCreado={cargar} />}
        {admin && (
          <section className="ucard">
            <h3>Usuarios registrados ({lista.length})</h3>
            {fallo && <div className="errbox">{fallo}</div>}
            {lista.map((u) => <FilaUsuario key={u.id} u={u} yo={sesion.usuario} onCambio={cargar} />)}
          </section>
        )}
        <MiPassword />
      </aside>
    </>
  );
}
