/**
 * Interfaz administrativa para emitir, listar y revocar tokens n8n.
 */
import { useCallback, useEffect, useState } from 'react';
import { api } from '../api';

export default function Tokens() {
  const [tokens, setTokens] = useState([]);
  const [nombre, setNombre] = useState('');
  const [dias, setDias] = useState('0');
  const [tokenNuevo, setTokenNuevo] = useState('');
  const [copiado, setCopiado] = useState(false);
  const [fallo, setFallo] = useState('');
  const [ocupado, setOcupado] = useState(false);

  const cargar = useCallback(async () => {
    try {
      setTokens((await api('tokens')).tokens);
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
    if (!window.confirm(`¿Revocar el token «${token.nombre}»? n8n dejará de poder enviar avisos con él.`)) return;
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

  return (
    <section className="ucard">
      <h3>Tokens para n8n</h3>
      <p className="mut">Crea un token para autenticar el nodo HTTP que envía avisos a <code>ingest.php</code>. El token completo solo se muestra al crearlo.</p>

      <form className="token-form" onSubmit={crear}>
        <label>
          Nombre
          <input value={nombre} onChange={(e) => setNombre(e.target.value)} maxLength={100}
            placeholder="n8n producción" required />
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
          <p>n8n no podrá volver a mostrarlo. Configura la cabecera <code>Authorization</code> con el valor <code>Bearer</code> seguido del token.</p>
          <div className="token-copy-row">
            <input aria-label="Token nuevo" readOnly value={tokenNuevo}
              onFocus={(e) => e.currentTarget.select()} />
            <button className="btn sec" type="button" onClick={copiar}>{copiado ? 'Copiado' : 'Copiar'}</button>
          </div>
        </div>
      )}

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
