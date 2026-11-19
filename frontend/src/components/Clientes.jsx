/**
 * Vistas jerarquicas de clientes, equipos, asignacion y detalle del historial por equipo.
 */
import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { api } from '../api';
import { norm, TIPOS } from '../logic';
import Tag from './Tag';
import Detalle from './Detalle';
import MonitorDetalle from './MonitorDetalle';
import RevisionEquipo from './RevisionEquipo';
import Paginacion from './Paginacion';
import SelectorCliente from './SelectorCliente';
import PageHeading from './PageHeading';
import BarraFiltros from './BarraFiltros';
import { usePagination } from '../hooks/usePagination';

const unir = (...v) => v.filter(Boolean).join(' · ');
const fechaLocal = (value) => {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString('es-ES');
};
const DIAS_SEMANA = [
  ['L', 'Lunes'], ['M', 'Martes'], ['X', 'Miércoles'], ['J', 'Jueves'],
  ['V', 'Viernes'], ['S', 'Sábado'], ['D', 'Domingo'],
];

function AliasEquipo({ equipo, admin, onSaved }) {
  const [alias, setAlias] = useState(equipo.monitor_alias || '');
  const [guardandoAlias, setGuardandoAlias] = useState(false);
  const [estado, setEstado] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    setAlias(equipo.monitor_alias || '');
    setEstado('');
    setError('');
  }, [equipo.id, equipo.monitor_alias]);

  const guardar = async (event) => {
    event.preventDefault();
    setGuardandoAlias(true);
    setEstado('');
    setError('');
    try {
      const result = await api('equipo_alias', { id: equipo.id, alias: alias.trim() });
      const nuevoAlias = result.alias || '';
      setAlias(nuevoAlias);
      onSaved(equipo.id, nuevoAlias);
      setEstado(nuevoAlias ? 'Alias guardado.' : 'Alias eliminado.');
    } catch (e) {
      setError(e.message || 'No se pudo guardar el alias.');
    } finally {
      setGuardandoAlias(false);
    }
  };

  if (!admin) return null;
  return <form className="equipo-alias-editor" onSubmit={guardar}>
    <label htmlFor={`alias-equipo-${equipo.id}`}>Alias (se muestra junto al nombre original)</label>
    <div>
      <input id={`alias-equipo-${equipo.id}`} value={alias} maxLength={100}
        placeholder="Ej.: Recepción, Caja 1…" disabled={guardandoAlias}
        onChange={(event) => { setAlias(event.target.value); setEstado(''); }} />
      <button className="btn sec" type="submit" disabled={guardandoAlias || alias.trim() === (equipo.monitor_alias || '')}>
        {guardandoAlias ? 'Guardando…' : 'Guardar alias'}
      </button>
    </div>
    <span className={`equipo-alias-estado${error ? ' error' : ''}`} role={error ? 'alert' : 'status'}>{error || estado}</span>
  </form>;
}

function TipoEquipoEditor({ equipo, admin, onSaved }) {
  const [tipo, setTipo] = useState(equipo.tipo_manual ? equipo.tipo : 'auto');
  const [guardando, setGuardando] = useState(false);
  const [estado, setEstado] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    setTipo(equipo.tipo_manual ? equipo.tipo : 'auto');
    setEstado('');
    setError('');
  }, [equipo.id, equipo.tipo, equipo.tipo_manual]);

  const guardar = async () => {
    setGuardando(true);
    setEstado('');
    setError('');
    try {
      const result = await api('equipo_tipo', { id: equipo.id, tipo });
      onSaved(equipo.id, result.tipo || null, result.tipo_manual || null);
      setEstado(tipo === 'auto' ? 'Tipo automático restablecido.' : 'Tipo de equipo guardado.');
    } catch (e) {
      setError(e.message || 'No se pudo guardar el tipo de equipo.');
    } finally {
      setGuardando(false);
    }
  };

  if (!admin) return null;
  const tipoActual = equipo.tipo_manual ? equipo.tipo : 'auto';
  return <div className="equipo-tipo-editor">
    <label htmlFor={`tipo-equipo-${equipo.id}`}>Tipo de equipo (independiente del alias)</label>
    <div>
      <select id={`tipo-equipo-${equipo.id}`} value={tipo} disabled={guardando} onChange={(event) => { setTipo(event.target.value); setEstado(''); }}>
        <option value="auto">Automático por nombre</option>
        <option value="usuario">Equipo de usuario</option>
        <option value="servidor">Servidor</option>
        <option value="programa">Equipo de programa</option>
      </select>
      <button type="button" className="btn sec" disabled={guardando || tipo === tipoActual} onClick={guardar}>
        {guardando ? 'Guardando…' : 'Guardar tipo'}
      </button>
    </div>
    <span className={`equipo-alias-estado${error ? ' error' : ''}`} role={error ? 'alert' : 'status'}>
      {error || estado || (equipo.tipo_manual ? 'Tipo fijado manualmente; las próximas copias conservarán esta elección.' : 'El sistema clasifica este equipo según su nombre.')}
    </span>
  </div>;
}

function RustDeskAcceso({ equipo, monitor, admin }) {
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

function HorarioSemana({ equipo, onSaved }) {
  const horarioActual = /^[01]{7}$/.test(equipo.dias_copia || '') && equipo.dias_copia.includes('1')
    ? equipo.dias_copia : '1111111';
  const [dias, setDias] = useState(horarioActual);
  const [guardando, setGuardando] = useState(false);
  const [error, setError] = useState('');
  const [guardado, setGuardado] = useState(false);

  useEffect(() => {
    setDias(horarioActual);
    setError('');
  }, [equipo.id, horarioActual]);

  const cambiar = (indice, marcado) => {
    const seleccion = dias.split('');
    if (!marcado && seleccion.filter((dia) => dia === '1').length === 1) {
      setError('Selecciona al menos un día.');
      setGuardado(false);
      return;
    }
    seleccion[indice] = marcado ? '1' : '0';
    setDias(seleccion.join(''));
    setError('');
    setGuardado(false);
  };

  const guardar = async () => {
    setGuardando(true);
    setError('');
    setGuardado(false);
    try {
      await api('equipo_horario', { id: equipo.id, dias_copia: dias });
      onSaved(equipo.id, dias);
      setGuardado(true);
    } catch (e) {
      setError(e.message);
    } finally {
      setGuardando(false);
    }
  };

  return <div className="equipo-horario">
    <fieldset disabled={guardando}>
      <legend>Días esperados para las copias</legend>
      <div className="equipo-dias">
        {DIAS_SEMANA.map(([abreviatura, nombre], indice) => (
          <label className={dias[indice] === '1' ? 'seleccionado' : ''} key={nombre} title={nombre}>
            <input type="checkbox" aria-label={nombre} checked={dias[indice] === '1'}
              onChange={(e) => cambiar(indice, e.target.checked)} />
            <span aria-hidden="true">{abreviatura}</span>
          </label>
        ))}
      </div>
    </fieldset>
    <button className="btn sec equipo-horario-guardar" disabled={guardando || dias === horarioActual}
      onClick={guardar}>{guardando ? 'Guardando…' : 'Guardar días'}</button>
    <span className={`equipo-horario-estado${error ? ' error' : ''}`} role={error ? 'alert' : 'status'} aria-live="polite">
      {error || (guardando ? 'Guardando horario…' : guardado ? 'Horario guardado.' : '')}
    </span>
  </div>;
}

function sugerirCliente(clientes, sugerido) {
  const s = norm(String(sugerido).trim());
  if (!s) return undefined;
  const exacto = clientes.find((c) => [c.nombre, c.codigo, unir(c.nombre, c.codigo)].some((v) => v && norm(String(v)) === s));
  if (exacto) return exacto;
  if (s.length < 4) return undefined;
  const parciales = clientes.filter((c) => {
    const n = norm(String(c.nombre || ''));
    return n.length >= 4 && (n.includes(s) || s.includes(n));
  });
  return parciales.length === 1 ? parciales[0] : undefined;
}

export default function Clientes({ sesion, refreshSignal = 0, clienteId = null, equipoId = null, onNavigate }) {
  const admin = sesion?.rol === 'admin';
  const [cat, setCat] = useState(null);
  const [equipos, setEquipos] = useState([]);
  const [detalleMonitor, setDetalleMonitor] = useState(null);
  const [cargandoMonitor, setCargandoMonitor] = useState(false);
  const [falloMonitor, setFalloMonitor] = useState('');
  const clienteAbierto = clienteId;
  const equipoAbierto = equipoId;
  const [busqueda, setBusqueda] = useState('');
  const [filtroSatbk, setFiltroSatbk] = useState('todos');
  const [ordenClientes, setOrdenClientes] = useState('az');
  const [filtroAgenteCliente, setFiltroAgenteCliente] = useState('todos');
  const [softwareCliente, setSoftwareCliente] = useState('');
  const [filtrosEquipo, setFiltrosEquipo] = useState({
    orden: 'nombre-az', agente: 'todos', copias: 'todos', tipo: '', software: '', desde: '', hasta: '',
  });
  const [asignaciones, setAsignaciones] = useState({});
  const [cargando, setCargando] = useState(true);
  const [sync, setSync] = useState(false);
  const [ultimaLectura, setUltimaLectura] = useState(null);
  const [fallo, setFallo] = useState('');
  const [mensaje, setMensaje] = useState('');
  const [guardando, setGuardando] = useState(null);
  const [historial, setHistorial] = useState([]);
  const [historialFallo, setHistorialFallo] = useState('');
  const [copiaSeleccionada, setCopiaSeleccionada] = useState(null);
  const [cargandoHistorial, setCargandoHistorial] = useState(false);
  const [cargandoMas, setCargandoMas] = useState(false);
  const [hayMasHistorial, setHayMasHistorial] = useState(false);
  const cargaActual = useRef(0);
  const cargaHistorialActual = useRef(0);
  const equiposActuales = useRef([]);
  const sincronizando = useRef(false);

  const cargar = useCallback(async (silencioso = false, duranteSync = false) => {
    if (sincronizando.current && !duranteSync) return { error: '', actualizado: false };
    const carga = ++cargaActual.current;
    if (!silencioso && !equiposActuales.current.length) setCargando(true);
    try {
      const [c, e] = await Promise.all([api('catalogo'), api('equipos')]);
      if (carga !== cargaActual.current) return { error: '', actualizado: false };
      if (!Array.isArray(e.equipos)) throw new Error('La respuesta de equipos no es válida.');
      if (equiposActuales.current.length && !e.equipos.length) {
        throw new Error('La actualización devolvió una lista vacía. Se mantienen los datos anteriores.');
      }
      setCat(c);
      equiposActuales.current = e.equipos;
      setEquipos(e.equipos);
      if (e.correo) setUltimaLectura(e.correo);
      setFallo('');
      return { error: '', actualizado: true };
    } catch (e) {
      if (carga !== cargaActual.current) return { error: '', actualizado: false };
      setFallo(e.message);
      return { error: e.message, actualizado: false };
    } finally {
      if (carga === cargaActual.current) setCargando(false);
    }
  }, []);

  const cargarHistorial = useCallback(async (equipoId, cursor = null, anexar = false) => {
    const carga = ++cargaHistorialActual.current;
    setHistorialFallo('');
    if (anexar) setCargandoMas(true);
    else setCargandoHistorial(true);
    try {
      const respuesta = await api('equipo_historial', {
        equipo_id: equipoId,
        ...(cursor ? { cursor_fecha: cursor.cursor_fecha, cursor_id: cursor.id } : {}),
      });
      if (carga !== cargaHistorialActual.current) return;
      setHistorial((actual) => anexar ? [...actual, ...respuesta.copias] : respuesta.copias);
      setHayMasHistorial(Boolean(respuesta.hay_mas_historial));
    } catch (e) {
      if (carga === cargaHistorialActual.current) setHistorialFallo(e.message);
    } finally {
      if (carga === cargaHistorialActual.current) {
        setCargandoHistorial(false);
        setCargandoMas(false);
      }
    }
  }, []);

  useEffect(() => {
    cargar();
    const timer = setInterval(() => cargar(true), 60000);
    return () => clearInterval(timer);
  }, [cargar]);

  useEffect(() => {
    if (refreshSignal > 0) {
      cargar(true);
      if (equipoAbierto !== null) cargarHistorial(equipoAbierto);
    }
  }, [refreshSignal, equipoAbierto, cargar, cargarHistorial]);

  useEffect(() => {
    if (equipoAbierto === null) {
      cargaHistorialActual.current += 1;
      setHistorial([]);
      setHistorialFallo('');
      setHayMasHistorial(false);
      return;
    }
    setHistorial([]);
    cargarHistorial(equipoAbierto);
  }, [equipoAbierto, cargarHistorial]);

  const clientes = cat?.clientes || [];
  const clientesConSatbk = clientes.filter((cliente) => cliente.tiene_satbk);
  const clientesSinSatbk = clientes.filter((cliente) => !cliente.tiene_satbk);
  const softwaresEquipo = [...new Set(equipos.map((equipo) => equipo.software).filter(Boolean))]
    .sort((a, b) => a.localeCompare(b, 'es', { sensitivity: 'base' }));
  const idsClientesActivos = new Set(clientes.map((cliente) => Number(cliente.id)));
  const idsClientesSinSatbk = new Set(clientesSinSatbk.map((cliente) => Number(cliente.id)));
  const equiposClienteInactivo = equipos.filter((equipo) => equipo.cliente_id && !idsClientesActivos.has(Number(equipo.cliente_id)));
  const equiposClienteSinSatbk = equipos.filter((equipo) => equipo.cliente_id && idsClientesSinSatbk.has(Number(equipo.cliente_id)));
  const clienteActual = clienteAbierto === 'sin-asignar'
    ? { nombre: 'Equipos sin asignar' }
    : clienteAbierto === 'sin-satbk'
      ? { nombre: 'Equipos de clientes sin SATBK' }
    : clienteAbierto === 'cliente-inactivo'
      ? { nombre: 'Cliente no activo' }
    : clientes.find((cliente) => Number(cliente.id) === Number(clienteAbierto));
  const equipoActual = equipos.find((equipo) => Number(equipo.id) === Number(equipoAbierto));
  const clientesAsignables = equipoActual?.tiene_copias
    ? clientes.filter((cliente) => cliente.tiene_satbk)
    : clientes;
  const clienteAsignadoActual = clientes.find((cliente) => Number(cliente.id) === Number(equipoActual?.cliente_id));
  const asignacionSinSatbk = Boolean(equipoActual?.tiene_copias && equipoActual?.cliente_id
    && clienteAsignadoActual && !clienteAsignadoActual.tiene_satbk);
  useEffect(() => {
    let activa = true;
    if (!equipoActual?.tiene_monitor) {
      setDetalleMonitor(null);
      setFalloMonitor('');
      setCargandoMonitor(false);
      return () => { activa = false; };
    }
    setDetalleMonitor(null);
    setFalloMonitor('');
    setCargandoMonitor(true);
    api(`monitor_equipo&id=${encodeURIComponent(equipoActual.id)}`)
      .then((result) => { if (activa) setDetalleMonitor(result); })
      .catch((error) => { if (activa) setFalloMonitor(error.message); })
      .finally(() => { if (activa) setCargandoMonitor(false); });
    return () => { activa = false; };
  }, [equipoActual?.id, equipoActual?.tiene_monitor, refreshSignal]);
  const equiposCliente = useMemo(() => {
    if (clienteAbierto === 'sin-asignar') return equipos.filter((equipo) => !equipo.cliente_id);
    if (clienteAbierto === 'sin-satbk') return equipos.filter((equipo) => equipo.cliente_id && idsClientesSinSatbk.has(Number(equipo.cliente_id)));
    if (clienteAbierto === 'cliente-inactivo') return equipos.filter((equipo) => equipo.cliente_id && !idsClientesActivos.has(Number(equipo.cliente_id)));
    if (clienteAbierto === null) return [];
    return equipos.filter((equipo) => Number(equipo.cliente_id) === Number(clienteAbierto));
  }, [equipos, clienteAbierto, cat]);
  const textoBusqueda = norm(busqueda.trim());
  const clientesPorSatbk = useMemo(() => {
    if (filtroSatbk === 'con') return clientes.filter((cliente) => cliente.tiene_satbk);
    if (filtroSatbk === 'sin') return clientes.filter((cliente) => !cliente.tiene_satbk);
    return clientes;
  }, [clientes, filtroSatbk]);
  const clientesPorEquipo = useMemo(() => clientesPorSatbk.filter((cliente) => {
    const relacionados = equipos.filter((equipo) => Number(equipo.cliente_id) === Number(cliente.id));
    if (filtroAgenteCliente === 'con' && !relacionados.some((equipo) => equipo.tiene_monitor)) return false;
    if (filtroAgenteCliente === 'sin' && relacionados.some((equipo) => equipo.tiene_monitor)) return false;
    if (softwareCliente && !relacionados.some((equipo) => equipo.software === softwareCliente)) return false;
    return true;
  }), [clientesPorSatbk, equipos, filtroAgenteCliente, softwareCliente]);
  const clientesFiltrados = useMemo(() => clientesPorEquipo.filter((cliente) => {
    const datos = unir(cliente.nombre, cliente.codigo);
    return !textoBusqueda || norm(datos).includes(textoBusqueda);
  }).sort((a, b) => String(a.nombre || '').localeCompare(String(b.nombre || ''), 'es', { sensitivity: 'base' }) * (ordenClientes === 'az' ? 1 : -1)),
  [clientesPorEquipo, textoBusqueda, ordenClientes]);
  const equiposFiltrados = useMemo(() => equiposCliente.filter((equipo) => {
    if (textoBusqueda && !norm(unir(equipo.nombre, equipo.monitor_alias, equipo.software, equipo.programa, equipo.sugerido, equipo.origen, equipo.tipo)).includes(textoBusqueda)) return false;
    if (filtrosEquipo.agente === 'con' && !equipo.tiene_monitor) return false;
    if (filtrosEquipo.agente === 'sin' && equipo.tiene_monitor) return false;
    if (filtrosEquipo.copias === 'con' && !equipo.tiene_copias) return false;
    if (filtrosEquipo.copias === 'sin' && equipo.tiene_copias) return false;
    if (filtrosEquipo.tipo && filtrosEquipo.tipo !== 'todos' && equipo.tipo !== filtrosEquipo.tipo) return false;
    if (filtrosEquipo.software && equipo.software !== filtrosEquipo.software) return false;
    if (filtrosEquipo.desde && (!equipo.fecha_creacion || equipo.fecha_creacion < filtrosEquipo.desde)) return false;
    if (filtrosEquipo.hasta && (!equipo.fecha_creacion || equipo.fecha_creacion > filtrosEquipo.hasta)) return false;
    return true;
  }).sort((a, b) => {
    if (filtrosEquipo.orden === 'nombre-za') return String(b.nombre || '').localeCompare(String(a.nombre || ''), 'es', { sensitivity: 'base' });
    if (filtrosEquipo.orden === 'alta-reciente') return String(b.fecha_creacion || '').localeCompare(String(a.fecha_creacion || '')) || String(a.nombre).localeCompare(String(b.nombre), 'es');
    if (filtrosEquipo.orden === 'alta-antigua') return String(a.fecha_creacion || '').localeCompare(String(b.fecha_creacion || '')) || String(a.nombre).localeCompare(String(b.nombre), 'es');
    if (filtrosEquipo.orden === 'informe-reciente') return String(b.monitor_last_seen || '').localeCompare(String(a.monitor_last_seen || '')) || String(a.nombre).localeCompare(String(b.nombre), 'es');
    return String(a.nombre || '').localeCompare(String(b.nombre || ''), 'es', { sensitivity: 'base' });
  }), [equiposCliente, textoBusqueda, filtrosEquipo]);
  const filtrosActivosClientes = [
    ...(busqueda.trim() ? [{ clave: 'busqueda', etiqueta: `Búsqueda: ${busqueda.trim()}` }] : []),
    ...(filtroSatbk !== 'todos' ? [{ clave: 'satbk', etiqueta: filtroSatbk === 'con' ? 'Con SATBK' : 'Sin SATBK' }] : []),
    ...(filtroAgenteCliente !== 'todos' ? [{ clave: 'agente', etiqueta: filtroAgenteCliente === 'con' ? 'Con agente' : 'Sin agente' }] : []),
    ...(softwareCliente ? [{ clave: 'software', etiqueta: `Programa: ${softwareCliente}` }] : []),
  ];
  const filtrosActivosEquipos = [
    ...(busqueda.trim() ? [{ clave: 'busqueda', etiqueta: `Búsqueda: ${busqueda.trim()}` }] : []),
    ...(filtrosEquipo.agente !== 'todos' ? [{ clave: 'agente', etiqueta: filtrosEquipo.agente === 'con' ? 'Con agente' : 'Sin agente' }] : []),
    ...(filtrosEquipo.copias !== 'todos' ? [{ clave: 'copias', etiqueta: filtrosEquipo.copias === 'con' ? 'Con copias' : 'Sin copias' }] : []),
    ...(filtrosEquipo.tipo ? [{ clave: 'tipo', etiqueta: `Tipo: ${filtrosEquipo.tipo}` }] : []),
    ...(filtrosEquipo.software ? [{ clave: 'software', etiqueta: `Programa: ${filtrosEquipo.software}` }] : []),
    ...(filtrosEquipo.desde ? [{ clave: 'desde', etiqueta: `Alta desde: ${filtrosEquipo.desde}` }] : []),
    ...(filtrosEquipo.hasta ? [{ clave: 'hasta', etiqueta: `Alta hasta: ${filtrosEquipo.hasta}` }] : []),
  ];
  const paginaClientes = usePagination(clientesFiltrados, 24, `${textoBusqueda}|${filtroSatbk}|${ordenClientes}|${filtroAgenteCliente}|${softwareCliente}`);
  const paginaEquipos = usePagination(equiposFiltrados, 24, `${clienteAbierto}|${textoBusqueda}|${JSON.stringify(filtrosEquipo)}`);
  const equiposSinAsignar = equipos.filter((equipo) => !equipo.cliente_id);
  const totalServidores = equiposCliente.filter((equipo) => equipo.tipo === 'servidor').length;
  const equipoSugerencia = equipoActual?.sugerido ? sugerirCliente(clientesAsignables, equipoActual.sugerido) : undefined;
  const otrosMismoNombre = equipoActual ? equipos.filter((otro) => Number(otro.id) !== Number(equipoActual.id)
    && norm(String(otro.nombre || '')) === norm(String(equipoActual.nombre || ''))) : [];
  const seleccionGuardada = asignaciones[equipoActual?.id];
  const seleccionGuardadaValida = clientesAsignables.some((cliente) => Number(cliente.id) === Number(seleccionGuardada));
  const clienteActualElegible = clientesAsignables.some((cliente) => Number(cliente.id) === Number(equipoActual?.cliente_id));
  const clienteElegido = seleccionGuardadaValida
    ? seleccionGuardada
    : asignacionSinSatbk ? '' : clienteActualElegible ? equipoActual?.cliente_id : equipoSugerencia?.id ?? '';
  const clienteElegidoInfo = clientes.find((cliente) => Number(cliente.id) === Number(clienteElegido));
  const hayCambioAsignacion = Number(clienteElegido) > 0 && Number(clienteElegido) !== Number(equipoActual?.cliente_id || 0);
  const u = cat?.ultima;

  const sincronizar = async () => {
    if (sincronizando.current) return;
    sincronizando.current = true;
    cargaActual.current += 1;
    setSync(true);
    setFallo('');
    setMensaje('');
    let falloSync = '';
    try { await api('sync', {}); }
    catch (e) { falloSync = e.message; }
    try {
      const resultado = await cargar(true, true);
      if (falloSync) setFallo(resultado.error ? `${falloSync}. No se pudieron actualizar los datos: ${resultado.error}` : falloSync);
      else if (resultado.actualizado) setMensaje('Lista de clientes actualizada.');
    } finally {
      sincronizando.current = false;
      setSync(false);
    }
  };

  const asignar = async () => {
    const cliente_id = Number(clienteElegido || 0);
    if (!equipoActual || !cliente_id) {
      setFallo(`Selecciona un cliente para ${equipoActual?.nombre || 'este equipo'}.`);
      return;
    }
    setGuardando(equipoActual.id);
    setFallo('');
    setMensaje('');
    try {
      const r = await api('equipo_asignar', { id: equipoActual.id, cliente_id });
      onNavigate(`/clientes/${cliente_id}/equipos/${equipoActual.id}`);
      setMensaje(`Asignación guardada. ${r.copias_importadas} copia${r.copias_importadas === 1 ? '' : 's'} incorporada${r.copias_importadas === 1 ? '' : 's'}.`);
      await cargar(true);
    } catch (e) { setFallo(e.message); }
    finally { setGuardando(null); }
  };

  const desasignar = async () => {
    if (!equipoActual) return;
    setGuardando(equipoActual.id);
    setFallo('');
    setMensaje('');
    try {
      const r = await api('equipo_desasignar', { id: equipoActual.id });
      onNavigate(`/clientes/sin-asignar/equipos/${equipoActual.id}`);
      setMensaje(`Equipo desasignado. ${r.copias_desasignadas} copia${r.copias_desasignadas === 1 ? '' : 's'} actualizada${r.copias_desasignadas === 1 ? '' : 's'}.`);
      await cargar(true);
    } catch (e) { setFallo(e.message); }
    finally { setGuardando(null); }
  };

  const vigilar = async (equipo, valor) => {
    setGuardando(equipo.id);
    setFallo('');
    try { await api('equipo_vigilar', { id: equipo.id, vigilar: valor }); await cargar(true); }
    catch (e) { setFallo(e.message); }
    finally { setGuardando(null); }
  };

  const actualizarHorario = (id, dias) => {
    const actualizar = (lista) => lista.map((item) => item.id === id ? { ...item, dias_copia: dias } : item);
    cargaActual.current += 1;
    equiposActuales.current = actualizar(equiposActuales.current);
    setEquipos(actualizar);
  };

  const actualizarAlias = (id, alias) => {
    const actualizar = (lista) => lista.map((item) => item.id === id ? { ...item, monitor_alias: alias || null } : item);
    cargaActual.current += 1;
    equiposActuales.current = actualizar(equiposActuales.current);
    setEquipos(actualizar);
  };

  const actualizarTipo = (id, tipo, tipoManual) => {
    const actualizar = (lista) => lista.map((item) => item.id === id ? { ...item, tipo, tipo_manual: tipoManual } : item);
    cargaActual.current += 1;
    equiposActuales.current = actualizar(equiposActuales.current);
    setEquipos(actualizar);
  };

  const actualizarRevisionEquipo = (id, revision) => {
    const actualizar = (lista) => lista.map((item) => item.id === id ? {
      ...item,
      errores_pendientes: revision.errores_pendientes,
      error_reviewed_at: revision.error_reviewed_at,
      error_reviewed_by: revision.error_reviewed_by,
      error_review_desc: revision.error_review_desc,
    } : item);
    cargaActual.current += 1;
    equiposActuales.current = actualizar(equiposActuales.current);
    setEquipos(actualizar);
  };

  const volverAClientes = () => {
    onNavigate('/clientes');
    setBusqueda('');
    setFallo('');
    setMensaje('');
  };
  const volverAEquipos = () => {
    onNavigate(`/clientes/${encodeURIComponent(String(clienteAbierto))}`);
    setBusqueda('');
    setFallo('');
    setMensaje('');
  };
  const quitarFiltroCliente = (clave) => {
    if (clave === 'busqueda') setBusqueda('');
    else if (clave === 'satbk') setFiltroSatbk('todos');
    else if (clave === 'agente') setFiltroAgenteCliente('todos');
    else if (clave === 'software') setSoftwareCliente('');
  };
  const limpiarFiltrosClientes = () => {
    setBusqueda('');
    setFiltroSatbk('todos');
    setFiltroAgenteCliente('todos');
    setSoftwareCliente('');
  };
  const quitarFiltroEquipo = (clave) => {
    if (clave === 'busqueda') setBusqueda('');
    else setFiltrosEquipo((actual) => ({ ...actual, [clave]: clave === 'agente' || clave === 'copias' ? 'todos' : '' }));
  };
  const limpiarFiltrosEquipo = () => {
    setBusqueda('');
    setFiltrosEquipo({ orden: 'nombre-az', agente: 'todos', copias: 'todos', tipo: '', software: '', desde: '', hasta: '' });
  };
  const abrirEquipoRelacionado = (otro) => {
    const grupo = otro.cliente_id ? `/clientes/${encodeURIComponent(String(otro.cliente_id))}` : '/clientes/sin-asignar';
    onNavigate(`${grupo}/equipos/${otro.id}`);
  };

  return <div className="clientes-page">
    <section className={`mapeo-panel${equipoActual ? ' equipo-panel-detalle' : ''}`} aria-labelledby="titulo-mapeo">
      <PageHeading id="titulo-mapeo" className={equipoActual
        ? `equipo-detalle-heading${equipoActual.tiene_monitor ? ' con-rustdesk' : ''}` : ''}
        eyebrow={equipoActual ? 'Equipo' : clienteActual ? 'Cliente' : 'Directorio de clientes'}
        title={equipoActual ? <>{equipoActual.nombre}{equipoActual.monitor_alias && <span className="equipo-alias-titulo"> · {equipoActual.monitor_alias}</span>}</>
          : clienteActual ? clienteActual.nombre : 'Clientes'}
        description={equipoActual
          ? unir(equipoActual.software, equipoActual.tipo ? TIPOS[equipoActual.tipo] : null,
            equipoActual.tiene_monitor ? 'Agente de Monitorización' : null,
            equipoActual.tiene_copias ? 'Con copias' : 'Sin copias recibidas')
          : clienteActual ? `${equiposCliente.length} equipos · ${totalServidores} servidores`
            : 'Clientes, asignaciones y equipos.'}
        actions={equipoActual?.tiene_monitor
          ? <RustDeskAcceso equipo={equipoActual} monitor={detalleMonitor?.monitor} admin={admin} />
          : !equipoActual && <div className="mapeo-kpi" aria-label={`${equiposSinAsignar.length} equipos sin asignar`}>
            <strong>{equiposSinAsignar.length}</strong><span>sin asignar</span>
          </div>} />
      {equipoActual && <div className="equipo-resumen-grid equipo-resumen-compacto" aria-label="Resumen del equipo">
        <div><span>Cliente</span><strong>{clienteAsignadoActual?.nombre || equipoActual.cliente || 'Sin asignar'}</strong></div>
        <div><span>Datos recibidos</span><strong className="equipo-fuentes">
          {equipoActual.tiene_copias && <span className="equipo-fuente">Copias</span>}
          {equipoActual.tiene_monitor && <span className="equipo-fuente">Agente de Monitorización</span>}
          {!equipoActual.tiene_copias && !equipoActual.tiene_monitor && <span>Sin datos</span>}
        </strong></div>
        <div><span>Tipo / programa</span><strong>{unir(equipoActual.tipo ? TIPOS[equipoActual.tipo] : null, equipoActual.programa, equipoActual.software) || 'No especificado'}</strong></div>
        <div><span>Última copia</span><strong>{historial[0]?.fecha || (cargandoHistorial ? 'Consultando…' : 'Sin copias')}</strong></div>
        {equipoActual.tiene_monitor && <div><span>Último informe del agente</span><strong>{detalleMonitor?.monitor?.timestampUtc
          ? fechaLocal(detalleMonitor.monitor.timestampUtc) : cargandoMonitor ? 'Consultando…' : falloMonitor ? 'No disponible' : 'Sin informes'}</strong></div>}
      </div>}

      {equipoActual && <section className="equipo-asignacion" aria-labelledby="titulo-asignacion-equipo">
        <div className="equipo-asignacion-heading">
          <div><h3 id="titulo-asignacion-equipo">Asignación de cliente</h3>
            <p>{equipoActual.cliente_id ? 'Cliente actual y cambio de asignación.' : 'Elige el cliente destino.'}</p></div>
          {clienteAsignadoActual && <span className="equipo-asignacion-actual">{clienteAsignadoActual.nombre}</span>}
        </div>
        <div className="equipo-asignacion-reglas" aria-label="Resumen de asignación">
          <span><strong>Regla</strong>{equipoActual.tiene_copias ? 'Solo clientes con SATBK' : 'SATBK opcional'}</span>
          <span><strong>Origen</strong>{equipoActual.tiene_copias ? 'Copias recibidas' : 'Sin copias'}</span>
          <span><strong>Selección</strong>{clienteElegidoInfo ? `${clienteElegidoInfo.nombre} · ${clienteElegidoInfo.tiene_satbk ? 'SATBK' : 'Sin SATBK'}` : 'Pendiente'}</span>
        </div>
        {asignacionSinSatbk && <div className="estado-alertas compacto" role="status">
          <p>Asignación conservada: el cliente actual no tiene SATBK. Para cambiar, elige un cliente con SATBK.</p>
        </div>}
        {equipoActual.tiene_copias && !clientesAsignables.length && <div className="estado-alertas compacto" role="status">
          <p>No hay clientes con SATBK en el catálogo. Actualiza la lista de clientes antes de asignar este equipo.</p>
        </div>}
        {!equipoActual.cliente_id && equipoSugerencia && <p className="equipo-asignacion-sugerencia">
          Sugerencia: <strong>{equipoSugerencia.nombre}</strong>
        </p>}
        <div className="equipo-asignacion-campos">
          <label htmlFor={`cliente-equipo-${equipoActual.id}`}>{equipoActual.cliente_id ? 'Cambiar cliente' : 'Buscar cliente'}</label>
          <SelectorCliente id={`cliente-equipo-${equipoActual.id}`} clientes={clientesAsignables}
            value={clienteElegido}
            onChange={(value) => setAsignaciones((actual) => ({ ...actual, [equipoActual.id]: value }))}
            disabled={!clientesAsignables.length} />
          <button className="btn" disabled={guardando === equipoActual.id || !clientesAsignables.length || !hayCambioAsignacion} onClick={asignar}>
            {guardando === equipoActual.id ? 'Guardando…' : !equipoActual.cliente_id && equipoSugerencia ? `Vincular con ${equipoSugerencia.nombre}` : equipoActual.cliente_id ? 'Cambiar cliente' : 'Asignar equipo'}
          </button>
          {equipoActual.cliente_id && <button className="btn sec" disabled={guardando === equipoActual.id} onClick={desasignar}>
            {guardando === equipoActual.id ? 'Guardando…' : 'Desasignar'}
          </button>}
        </div>
      </section>}

      {equipoActual && admin && <div className="equipo-editores">
        <AliasEquipo equipo={equipoActual} admin={admin} onSaved={actualizarAlias} />
        <TipoEquipoEditor equipo={equipoActual} admin={admin} onSaved={actualizarTipo} />
      </div>}

      {!equipoActual && !clienteActual && <BarraFiltros title="Filtrar clientes"
        search={{ id: 'clientes-buscar', value: busqueda, placeholder: 'Buscar cliente…',
          onChange: (event) => setBusqueda(event.target.value) }}
        fields={[
          { id: 'clientes-ordenar', label: 'Ordenar', type: 'select', value: ordenClientes,
            onChange: (event) => setOrdenClientes(event.target.value), options: [['az', 'Nombre: A a Z'], ['za', 'Nombre: Z a A']] },
          { id: 'clientes-agente', label: 'Agente Monitor', type: 'select', value: filtroAgenteCliente,
            onChange: (event) => setFiltroAgenteCliente(event.target.value), options: [['todos', 'Cualquier estado'], ['con', 'Con agente'], ['sin', 'Sin agente']] },
          { id: 'clientes-programa', label: 'Programa de copia', type: 'select', value: softwareCliente,
            onChange: (event) => setSoftwareCliente(event.target.value), options: [['', 'Todos'], ...softwaresEquipo.map((nombre) => [nombre, nombre])] },
        ]}
        actions={admin && <button className="btn sec" type="button" disabled={sync} onClick={sincronizar}>{sync ? 'Sincronizando…' : 'Actualizar clientes'}</button>}
        count={`${clientesFiltrados.length} de ${clientes.length} clientes`}
        activeFilters={filtrosActivosClientes} onRemoveFilter={quitarFiltroCliente} onClear={limpiarFiltrosClientes}>
        <div className="cliente-filtros" role="group" aria-label="Filtrar clientes por SATBK">
          <span className="cliente-filtros-label">SATBK</span>
          <div className="cliente-filtros-opciones">
            <button type="button" className={filtroSatbk === 'todos' ? 'activo' : ''} aria-pressed={filtroSatbk === 'todos'}
              onClick={() => setFiltroSatbk('todos')}>Todos <span>{clientes.length}</span></button>
            <button type="button" className={filtroSatbk === 'con' ? 'activo' : ''} aria-pressed={filtroSatbk === 'con'}
              onClick={() => setFiltroSatbk('con')}>Con SATBK <span>{clientesConSatbk.length}</span></button>
            <button type="button" className={filtroSatbk === 'sin' ? 'activo' : ''} aria-pressed={filtroSatbk === 'sin'}
              onClick={() => setFiltroSatbk('sin')}>Sin SATBK <span>{clientesSinSatbk.length}</span></button>
          </div>
        </div>
      </BarraFiltros>}

      {clienteActual && !equipoActual && <BarraFiltros title="Filtrar equipos"
        search={{ id: 'equipos-cliente-buscar', value: busqueda, placeholder: 'Buscar equipo o software…',
          onChange: (event) => setBusqueda(event.target.value) }}
        fields={[
          { id: 'equipos-ordenar', label: 'Ordenar', type: 'select', value: filtrosEquipo.orden,
            onChange: (event) => setFiltrosEquipo((actual) => ({ ...actual, orden: event.target.value })),
            options: [['nombre-az', 'Nombre: A a Z'], ['nombre-za', 'Nombre: Z a A'], ['alta-reciente', 'Alta: más reciente'],
              ['alta-antigua', 'Alta: más antigua'], ['informe-reciente', 'Último informe del agente']] },
          { id: 'equipos-agente', label: 'Agente Monitor', type: 'select', value: filtrosEquipo.agente,
            onChange: (event) => setFiltrosEquipo((actual) => ({ ...actual, agente: event.target.value })),
            options: [['todos', 'Todos'], ['con', 'Con agente'], ['sin', 'Sin agente']] },
          { id: 'equipos-copias', label: 'Copias', type: 'select', value: filtrosEquipo.copias,
            onChange: (event) => setFiltrosEquipo((actual) => ({ ...actual, copias: event.target.value })),
            options: [['todos', 'Todos'], ['con', 'Con copias'], ['sin', 'Sin copias']] },
          { id: 'equipos-tipo', label: 'Tipo de equipo', type: 'select', value: filtrosEquipo.tipo,
            onChange: (event) => setFiltrosEquipo((actual) => ({ ...actual, tipo: event.target.value })),
            options: [['', 'Todos'], ['usuario', 'Usuario'], ['servidor', 'Servidor'], ['programa', 'Programa']] },
          { id: 'equipos-programa', label: 'Programa de copia', type: 'select', value: filtrosEquipo.software,
            onChange: (event) => setFiltrosEquipo((actual) => ({ ...actual, software: event.target.value })),
            options: [['', 'Todos'], ...softwaresEquipo.map((nombre) => [nombre, nombre])] },
          { id: 'equipos-desde', label: 'Alta desde', type: 'date', value: filtrosEquipo.desde, max: filtrosEquipo.hasta || undefined,
            onChange: (event) => setFiltrosEquipo((actual) => ({ ...actual, desde: event.target.value })) },
          { id: 'equipos-hasta', label: 'Alta hasta', type: 'date', value: filtrosEquipo.hasta, min: filtrosEquipo.desde || undefined,
            onChange: (event) => setFiltrosEquipo((actual) => ({ ...actual, hasta: event.target.value })) },
        ]}
        actions={<>
          <button className="btn sec" type="button" onClick={volverAClientes}>← Volver</button>
          {admin && <button className="btn sec" type="button" disabled={sync} onClick={sincronizar}>{sync ? 'Sincronizando…' : 'Actualizar clientes'}</button>}
        </>}
        count={`${equiposFiltrados.length} equipos`} activeFilters={filtrosActivosEquipos}
        onRemoveFilter={quitarFiltroEquipo} onClear={limpiarFiltrosEquipo} />}

      {equipoActual && <div className="mapeo-toolbar">
        <button className="btn sec" type="button" onClick={volverAEquipos}>← Volver a equipos</button>
        {equipoActual.tiene_monitor && <button className="btn" type="button" onClick={() => onNavigate(`/eventos?equipo_id=${equipoActual.id}`)}>Ver eventos de Windows</button>}
        {admin && <button className="btn sec" type="button" disabled={sync} onClick={sincronizar}>{sync ? 'Sincronizando…' : 'Actualizar clientes'}</button>}
      </div>}

      {(ultimaLectura?.alerta || ultimaLectura?.ok === 0 || u?.ok === 0) && <div className="estado-alertas" role="status">
        {ultimaLectura?.alerta && <p>No se reciben avisos de copias desde hace {ultimaLectura.horas_desde ?? 'más de'} h.</p>}
        {!ultimaLectura?.alerta && ultimaLectura?.ok === 0 && <p>La última recepción de copias terminó con errores.</p>}
        {u?.ok === 0 && <details>
          <summary>Falló la sincronización de clientes</summary>
          {u.mensaje && <p>{u.mensaje}</p>}
        </details>}
      </div>}
      {fallo && <div className="errbox" role="alert">{fallo} <button className="btn sec" onClick={() => cargar()}>Reintentar</button></div>}
      {mensaje && <div className="okmsg" role="status">{mensaje}</div>}
      {sync && <p className="sync-estado" role="status" aria-live="polite">Actualizando los clientes.</p>}
      {admin && cat && !cat.configurado && <div className="errbox">La API de clientes no está configurada en <code>config/config.php</code>.</div>}
      {cargando && !equipos.length && !cat ? <div className="mapeo-vacio">Cargando clientes…</div> : (
        <>
          {!clienteActual && !equipoActual && <>
            {clientesFiltrados.length || (!filtrosActivosClientes.length && (equiposSinAsignar.length || equiposClienteSinSatbk.length || equiposClienteInactivo.length)) ? <div className="cliente-grid">
              {paginaClientes.items.map((cliente) => {
                const asignados = equipos.filter((equipo) => Number(equipo.cliente_id) === Number(cliente.id));
                const servidores = asignados.filter((equipo) => equipo.tipo === 'servidor').length;
                return <button type="button" className="cliente-tarjeta" key={cliente.id}
                  onClick={() => { onNavigate(`/clientes/${cliente.id}`); setBusqueda(''); }}>
                  <span className="cliente-tarjeta-flecha">›</span>
                  <span className="cliente-tarjeta-titulo">
                    <strong>{cliente.nombre}</strong>
                    <span className={`cliente-satbk${cliente.tiene_satbk ? ' activo' : ''}`}>
                      <span className="cliente-satbk-punto" aria-hidden="true" />
                      {cliente.tiene_satbk ? 'SATBK' : 'Sin SATBK'}
                    </span>
                  </span>
                  <span className="cliente-conteo">{asignados.length} equipos · {servidores} servidores</span>
                </button>;
              })}
              {!filtrosActivosClientes.length && equiposSinAsignar.length > 0 && <button type="button" className="cliente-tarjeta cliente-tarjeta-pendiente"
                onClick={() => { onNavigate('/clientes/sin-asignar'); setBusqueda(''); }}>
                <span className="cliente-tarjeta-flecha">›</span>
                <strong>Sin asignar</strong>
                <span className="cliente-conteo">{equiposSinAsignar.length} equipos por vincular</span>
              </button>}
              {!filtrosActivosClientes.length && <button type="button" className="cliente-tarjeta cliente-tarjeta-pendiente"
                onClick={() => { onNavigate('/clientes/sin-satbk'); setBusqueda(''); }}>
                <span className="cliente-tarjeta-flecha">›</span>
                <strong>Equipos sin SATBK</strong>
                <span className="cliente-conteo">{equiposClienteSinSatbk.length} equipos asignados a clientes sin SATBK</span>
              </button>}
              {!filtrosActivosClientes.length && equiposClienteInactivo.length > 0 && <button type="button" className="cliente-tarjeta cliente-tarjeta-pendiente"
                onClick={() => { onNavigate('/clientes/cliente-inactivo'); setBusqueda(''); }}>
                <span className="cliente-tarjeta-flecha">›</span>
                <strong>Cliente no activo</strong>
                <span className="cliente-conteo">{equiposClienteInactivo.length} equipos conservados</span>
              </button>}
            </div> : <div className="mapeo-vacio">{cargando ? 'Cargando clientes y equipos…' : fallo ? 'No se pudieron cargar los clientes y equipos.' : filtrosActivosClientes.length ? 'Ningún cliente coincide con los filtros seleccionados.' : 'No hay clientes ni equipos para mostrar.'}</div>}
            <Paginacion total={clientesFiltrados.length} pagina={paginaClientes.page} paginas={paginaClientes.pageCount}
              porPagina={paginaClientes.pageSize} onCambiar={paginaClientes.setPage} etiqueta="clientes" />
          </>}

          {clienteActual && !equipoActual && <>
            {equiposFiltrados.length ? <div className="equipo-lista-navegable">
              {paginaEquipos.items.map((equipo) => (
                <article className="equipo-navegable" key={equipo.id}>
                  <button type="button" className="equipo-navegable-abrir"
                    onClick={() => onNavigate(`/clientes/${encodeURIComponent(String(clienteAbierto))}/equipos/${equipo.id}`)}>
                    <span className="equipo-navegable-info">
                      <strong>{equipo.nombre}{equipo.monitor_alias && <small className="equipo-alias-lista"> · {equipo.monitor_alias}</small>}</strong>
                      <span>{unir(equipo.software, equipo.programa || TIPOS[equipo.tipo], equipo.fecha_creacion ? `Alta ${equipo.fecha_creacion}` : null)}</span>
                      <span className="equipo-navegable-estados">
                        <span className={`estado-chip${equipo.tiene_copias ? ' ok' : ''}`}>{equipo.tiene_copias ? 'Copias' : 'Sin copias'}</span>
                        <span className={`estado-chip${equipo.tiene_monitor ? ' ok' : ''}`}>{equipo.tiene_monitor ? 'Agente' : 'Sin agente'}</span>
                      </span>
                    </span>
                    <span className="equipo-tipo-pill">{equipo.tipo === 'servidor' ? 'Servidor' : 'Equipo'}</span>
                    <span className="equipo-tarjeta-flecha">›</span>
                  </button>
                </article>
              ))}
            </div> : <div className="mapeo-vacio">{cargando ? 'Cargando equipos…' : fallo ? 'No se pudieron cargar los equipos.' : filtrosActivosEquipos.length
              ? 'Ningún equipo coincide con los filtros seleccionados.'
              : clienteAbierto === 'sin-satbk' ? 'No hay equipos asignados a clientes sin SATBK.'
                : clienteAbierto === 'sin-asignar' ? 'No hay equipos sin asignar.'
                  : 'Este cliente todavía no tiene equipos asociados.'}</div>}
            <Paginacion total={equiposFiltrados.length} pagina={paginaEquipos.page} paginas={paginaEquipos.pageCount}
              porPagina={paginaEquipos.pageSize} onCambiar={paginaEquipos.setPage} etiqueta="equipos" />
          </>}

          {equipoActual && <div className="equipo-historial-vista">
            <RevisionEquipo equipo={equipoActual} onSaved={actualizarRevisionEquipo} />

            {otrosMismoNombre.length > 0 && <details className="equipo-coincidencias">
              <summary>{otrosMismoNombre.length} posible{otrosMismoNombre.length === 1 ? '' : 's'} duplicado{otrosMismoNombre.length === 1 ? '' : 's'} por nombre; comprobar antes de combinar</summary>
              <div>{otrosMismoNombre.map((otro) => <button type="button" key={otro.id} onClick={() => abrirEquipoRelacionado(otro)}>
                <strong>{otro.nombre}</strong><span>{otro.cliente || 'Sin asignar'} · {unir(otro.software, otro.programa, otro.tipo ? TIPOS[otro.tipo] : null) || 'Sin tipo'}</span>
              </button>)}</div>
            </details>}

            <details className="equipo-vigilancia">
              <summary><span>Avisos por falta de copias</span><strong>{equipoActual.vigilar !== 0 ? 'Activos' : 'Desactivados'}</strong></summary>
              <div className="equipo-vigilancia-contenido">
                <label className="equipo-vigilar">
                  <input type="checkbox" checked={equipoActual.vigilar !== 0} disabled={guardando === equipoActual.id}
                    onChange={(e) => vigilar(equipoActual, e.target.checked)} /> Avisar si no llegan copias
                </label>
                {equipoActual.vigilar !== 0 && <HorarioSemana equipo={equipoActual} onSaved={actualizarHorario} />}
              </div>
            </details>
            <div className={`equipo-historial-columnas${equipoActual.tiene_monitor ? ' con-monitor' : ''}`}>
              <section className="historial-equipo" aria-labelledby="titulo-historial-equipo">
                <div className="historial-equipo-heading">
                  <h3 id="titulo-historial-equipo">Historial de copias</h3>
                  <span>{historial.length}{hayMasHistorial ? '+' : ''}</span>
                </div>
                {cargandoHistorial && !historial.length ? <div className="mapeo-vacio">Cargando historial…</div>
                  : historial.length ? <div className="historial-equipo-lista">
                    {historial.map((copia) => <button type="button" className="historial-equipo-fila historial-equipo-boton" key={copia.id}
                      onClick={() => setCopiaSeleccionada(copia)} aria-label={`Ver detalle de la copia del ${copia.fecha}`}>
                      <time>{copia.fecha}</time>
                      <Tag c={copia} />
                      {copia.desc_error && <span className="historial-equipo-error">{copia.codigo ? `${copia.codigo} · ` : ''}{copia.desc_error}</span>}
                    </button>)}
                  </div> : !historialFallo && <div className="mapeo-vacio">Todavía no hay copias registradas para este equipo.</div>}
                {historialFallo && <div className="errbox" role="alert">{historialFallo}
                  <button className="btn sec" onClick={() => cargarHistorial(equipoActual.id)}>Reintentar</button>
                </div>}
                {hayMasHistorial && <button className="btn sec" disabled={cargandoMas} onClick={() => {
                  const ultima = historial[historial.length - 1];
                  cargarHistorial(equipoActual.id, ultima, true);
                }}>{cargandoMas ? 'Cargando…' : 'Cargar copias anteriores'}</button>}
              </section>
              {equipoActual.tiene_monitor && <details className="equipo-monitor-detalle">
                <summary>Estado y especificaciones técnicas</summary>
                <MonitorDetalle equipo={equipoActual} datos={detalleMonitor}
                  cargando={cargandoMonitor} error={falloMonitor} esAdmin={admin}
                  clienteNombre={clienteAsignadoActual?.nombre || ''} />
              </details>}
            </div>
          </div>}
        </>
      )}
    </section>
    {copiaSeleccionada && <Detalle modo="dialog" c={copiaSeleccionada}
      onClose={() => setCopiaSeleccionada(null)} />}
  </div>;
}
