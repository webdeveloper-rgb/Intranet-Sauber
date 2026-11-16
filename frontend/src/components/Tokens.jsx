/**
 * Interfaz administrativa para emitir, listar y revocar tokens de integraciones.
 */
import { useCallback, useEffect, useState } from 'react';
import { api } from '../api';

export default function Tokens() {
  const [tokens, setTokens] = useState([]);
  const [nombre, setNombre] = useState('');
  const [dias, setDias] = useState('0');
  const [tokenNuevo, setTokenNuevo] = useState('');
  const [enrollments, setEnrollments] = useState([]);
  const [codigoNuevo, setCodigoNuevo] = useState('');
  const [copiado, setCopiado] = useState(false);
  const [codigoCopiado, setCodigoCopiado] = useState(false);
  const [fallo, setFallo] = useState('');
  const [ocupado, setOcupado] = useState(false);

  const cargar = useCallback(async () => {
    try {
      const [tokenData, enrollmentData] = await Promise.all([
        api('tokens'),
        api('monitor_enrollments'),
      ]);
      setTokens(tokenData.tokens);
      setEnrollments(enrollmentData.enrollments);
      setFallo('');
    } catch (e) {
      setFallo(e.message);
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const crear = async (e) => {
    e.preventDefault();
    if (!nombre.trim()) return;
    setOcupado(true);
    setFallo('');
    setTokenNuevo('');
    setCopiado(false);
    try {
      const respuesta = await api('token_crear', { nombre: nombre.trim(), dias: Number(dias) });
      setTokenNuevo(respuesta.token);
      setNombre('');
      await cargar();
    } catch (error) {
      setFallo(error.message);
    } finally {
      setOcupado(false);
    }
  };

  const revocar = async (token) => {
    if (!window.confirm(`¿Revocar el token «${token.nombre}»? La integración dejará de poder enviar datos con él.`)) return;
    setOcupado(true);
    setFallo('');
    try {
      await api('token_revocar', { id: token.id });
      if (tokenNuevo && token.prefijo && tokenNuevo.startsWith(token.prefijo)) setTokenNuevo('');
      await cargar();
    } catch (error) {
      setFallo(error.message);
    } finally {
      setOcupado(false);
    }
  };

  const copiar = async () => {
    try {
      await navigator.clipboard.writeText(tokenNuevo);
      setCopiado(true);
    } catch {
      setFallo('No se pudo copiar automáticamente. Selecciona el token y cópialo manualmente.');
    }
  };

  const crearCodigo = async () => {
    setOcupado(true);
    setFallo('');
    setCodigoNuevo('');
    setCodigoCopiado(false);
    try {
      const respuesta = await api('monitor_enrollment_crear', {});
      setCodigoNuevo(respuesta.code);
      await cargar();
    } catch (error) {
      setFallo(error.message);
    } finally {
      setOcupado(false);
    }
  };

  const revocarCodigo = async (enrollment) => {
    if (!window.confirm(`¿Revocar el código ${enrollment.code_prefix}…?`)) return;
    setOcupado(true);
    setFallo('');
    try {
      await api('monitor_enrollment_revocar', { id: enrollment.id });
      if (codigoNuevo && codigoNuevo.startsWith(enrollment.code_prefix)) setCodigoNuevo('');
      await cargar();
    } catch (error) {
      setFallo(error.message);
    } finally {
      setOcupado(false);
    }
  };

  const copiarCodigo = async () => {
    try {
      await navigator.clipboard.writeText(codigoNuevo);
      setCodigoCopiado(true);
    } catch {
      setFallo('No se pudo copiar automáticamente. Selecciona el código y cópialo manualmente.');
    }
  };

  return (
    <section className="ucard">
      <h3>Tokens de integraciones</h3>
      <p className="mut">Los tokens API se utilizan para integraciones como n8n. El instalador automatizado de Monitor-Equipos puede usar un token compartido, pero cualquiera que obtenga el instalador podría extraerlo; los códigos de alta individuales son más seguros.</p>

      <form className="token-form" onSubmit={crear}>
        <label>
          Nombre
          <input value={nombre} onChange={(e) => setNombre(e.target.value)} maxLength={100}
            placeholder="Monitor-Equipos - equipo" required />
        </label>
        <label>
          Caducidad
          <select value={dias} onChange={(e) => setDias(e.target.value)}>
            <option value="0">Sin caducidad</option>
            <option value="30">30 días</option>
            <option value="90">90 días</option>
            <option value="365">1 año</option>
          </select>
        </label>
        <button className="btn" disabled={ocupado || !nombre.trim()} type="submit">
          {ocupado ? 'Guardando…' : 'Crear token'}
        </button>
      </form>

      {fallo && <div className="errbox" role="alert">{fallo}</div>}
      {tokenNuevo && (
        <div className="token-created" role="status">
          <b>Copia ahora el token completo</b>
          <p>No se podrá volver a mostrar. Configura la cabecera <code>Authorization</code> con el valor <code>Bearer</code> seguido del token.</p>
          <div className="token-copy-row">
            <input aria-label="Token nuevo" readOnly value={tokenNuevo}
              onFocus={(e) => e.currentTarget.select()} />
            <button className="btn sec" type="button" onClick={copiar}>{copiado ? 'Copiado' : 'Copiar'}</button>
          </div>
        </div>
      )}

      <div className="token-list">
        <h3>Alta de Monitor-Equipos</h3>
        <p className="mut">Genera un código válido durante 15 minutos. Se puede usar una sola vez y el instalador no necesita una clave compartida.</p>
        <button className="btn" disabled={ocupado} type="button" onClick={crearCodigo}>
          {ocupado ? 'Generando…' : 'Crear código de alta'}
        </button>
        {codigoNuevo && (
          <div className="token-created" role="status">
            <b>Copia ahora el código de alta</b>
            <p>Introdúcelo en el instalador de Monitor-Equipos. Solo se mostrará esta vez.</p>
            <div className="token-copy-row">
              <input aria-label="Código de alta nuevo" readOnly value={codigoNuevo}
                onFocus={(e) => e.currentTarget.select()} />
              <button className="btn sec" type="button" onClick={copiarCodigo}>
                {codigoCopiado ? 'Copiado' : 'Copiar'}
              </button>
            </div>
          </div>
        )}
        {enrollments.map((enrollment) => (
          <div className={'urow' + (!enrollment.active ? ' off' : '')} key={enrollment.id}>
            <div className="uinfo">
              <b>{enrollment.code_prefix}…</b>
              <small>Creado por {enrollment.created_by} · {enrollment.created_at}</small>
              <small>{enrollment.consumed_at
                ? `Utilizado ${enrollment.consumed_at} · equipo ${enrollment.machine_id}`
                : enrollment.revoked_at
                  ? `Revocado ${enrollment.revoked_at}`
                  : enrollment.active
                    ? `Caduca ${enrollment.expires_at}`
                    : `Caducado ${enrollment.expires_at}`}</small>
            </div>
            {!!enrollment.active && <button className="btn sec" disabled={ocupado}
              type="button" onClick={() => revocarCodigo(enrollment)}>Revocar</button>}
          </div>
        ))}
        {enrollments.length === 0 && <p className="mut">Aún no hay códigos de alta.</p>}
      </div>

      <div className="token-list">
        {tokens.map((token) => (
          <div className={'urow' + (!token.activo ? ' off' : '')} key={token.id}>
            <div className="uinfo">
              <b>{token.nombre}</b>
              <small>{token.prefijo}… · creado por {token.creado_por} · {token.creado || 'sin fecha'}</small>
              <small>{token.activo ? `Último uso: ${token.ultimo_uso || 'nunca'}` : 'Revocado'}
                {token.caduca ? ` · caduca ${token.caduca}` : ''}</small>
            </div>
            {!!token.activo && <button className="btn sec" disabled={ocupado}
              type="button" onClick={() => revocar(token)}>Revocar</button>}
          </div>
        ))}
        {tokens.length === 0 && !fallo && <p className="mut">Aún no hay tokens creados.</p>}
      </div>
    </section>
  );
}
