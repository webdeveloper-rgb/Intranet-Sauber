/**
 * Composicion de vistas, estado global, rutas del navegador y refresco de novedades.
 */
import { useState, useMemo, useEffect, useCallback, useReducer, useRef } from 'react';
import { iniciarSesion, api } from '../shared/api/client';
import { useCopias } from '../features/backups/hooks/useCopias';
import { TEST, norm, contadores, repeticiones, claveEquipo, coincideTipo } from '../shared/lib/logic';
import Header from './layout/Header';
import Kpis from '../features/backups/components/Kpis';
import Filtros from '../features/backups/components/Filtros';
import Tabla from '../features/backups/components/Tabla';
import Detalle from '../features/backups/components/Detalle';
import Clientes from '../features/clients/pages/Clientes';
import Usuarios from '../features/account/components/Usuarios';
import Eventos from '../features/events/pages/Eventos';
import Notificaciones from '../features/notifications/components/Notificaciones';
import Inicio from '../features/dashboard/pages/Inicio';
import Resumen from '../features/dashboard/pages/Resumen';
import Equipos from '../features/equipment/pages/Equipos';
import InformeCopias from '../features/backups/pages/InformeCopias';
import PorClasificar from '../features/backups/pages/PorClasificar';
import PageHeading from '../shared/ui/PageHeading';
import Paginacion from '../shared/ui/Paginacion';
import { usePagination } from '../shared/hooks/usePagination';
import { leerRuta, rutaHref } from './routes';

const filtrosIniciales = () => {
  return { cli: '', tipo: '', software: '', desde: '', hasta: '', orden: 'fecha-desc', horas: 24 };
};
const claveIncidenciaCopia = (c) => [
  c.equipo_id || `${c.cliente}|${c.equipo}`,
  c.software || '',
  c.codigo || '',
  c.desc_error || '',
].join('\u001f');
const acumularErrores = (copias) => {
  const grupos = new Map();
  const resultado = [];
  copias.forEach((copia) => {
    if (!['ERROR', 'REVISION'].includes(copia.estado) || copia.codigo === 'SIN_COPIA') {
      resultado.push(copia);
      return;
    }
    const clave = claveIncidenciaCopia(copia);
    const existente = grupos.get(clave);
    if (!existente) {
      const agrupada = { ...copia, acumuladas: 1, fecha_primera: copia.fecha, fecha_ultima: copia.fecha };
      grupos.set(clave, agrupada);
      resultado.push(agrupada);
      return;
    }
    const acumuladas = existente.acumuladas + 1;
    const fechaPrimera = String(copia.fecha) < String(existente.fecha_primera) ? copia.fecha : existente.fecha_primera;
    const fechaUltima = String(copia.fecha) > String(existente.fecha_ultima) ? copia.fecha : existente.fecha_ultima;
    if (String(copia.fecha) > String(existente.fecha)) Object.assign(existente, copia);
    existente.acumuladas = acumuladas;
    existente.fecha_primera = fechaPrimera;
    existente.fecha_ultima = fechaUltima;
  });
  return resultado;
};

export default function App() {
  const [sesion, setSesion] = useState(null);
  const [falloSesion, setFalloSesion] = useState('');
  const [ruta, setRuta] = useState(leerRuta);
  const [filtro, setFiltro] = useState('todos');
  const [f, setF] = useReducer((s, p) => ({ ...s, ...p }), undefined, filtrosIniciales);
  const vista = ruta.vista;
  const copias = useCopias(f.desde, f.hasta, vista === 'copias' || vista === 'por-clasificar');
  const [copiaSeleccionada, setCopiaSeleccionada] = useState(null);
  const [cuentaAbierta, setCuentaAbierta] = useState(false);
  const [notificacionesAbiertas, setNotificacionesAbiertas] = useState(false);
  const [actualizacionEquipos, setActualizacionEquipos] = useState(0);
  const historialInterno = useRef([]);
  const hrefActual = useRef(`${window.location.pathname}${window.location.search}`);
  const cargarSesion = useCallback(() => {
    setFalloSesion('');
    iniciarSesion().then(setSesion).catch((error) => setFalloSesion(error.message));
  }, []);
  useEffect(() => { cargarSesion(); }, [cargarSesion]);

  const estadoSesion = falloSesion
    ? <div className="empty-state" role="alert"><strong>No se pudo cargar el panel</strong><span>{falloSesion}</span><button className="btn sec" onClick={cargarSesion}>Reintentar</button></div>
    : <div className="empty-state" role="status">Comprobando sesión…</div>;

  const navegar = useCallback((path, { replace = false } = {}) => {
    const href = rutaHref(path);
    const actual = `${window.location.pathname}${window.location.search}`;
    if (actual !== href) {
      if (!replace) historialInterno.current.push(actual);
      window.history[replace ? 'replaceState' : 'pushState']({ panelSauber: true }, '', href);
      hrefActual.current = href;
    }
    setRuta(leerRuta());
    if (!/^\/copias\/\d+$/.test(path)) setCopiaSeleccionada(null);
    window.scrollTo(0, 0);
  }, []);

  const volver = useCallback(() => {
    if (historialInterno.current.length > 0) {
      window.history.back();
      return;
    }
    const actual = leerRuta();
    let destino = '/';
    if (actual.vista === 'clientes') {
      if (actual.equipoId) destino = actual.clienteId
        ? `/clientes/${encodeURIComponent(String(actual.clienteId))}`
        : '/clientes';
      else if (actual.clienteId) destino = '/clientes';
    } else if (actual.vista === 'eventos' && actual.equipoId) {
      destino = '/clientes';
    } else if (actual.vista === 'copias' && actual.copiaId) {
      destino = '/copias';
    }
    navegar(destino);
  }, [navegar]);

  useEffect(() => {
    const sincronizarRuta = () => {
      const actual = `${window.location.pathname}${window.location.search}`;
      const anterior = hrefActual.current;
      const indiceAnterior = historialInterno.current.lastIndexOf(actual);
      if (indiceAnterior >= 0) historialInterno.current.length = indiceAnterior;
      else if (actual !== anterior) historialInterno.current.push(anterior);
      hrefActual.current = actual;
      setRuta(leerRuta());
      if (!leerRuta().copiaId) setCopiaSeleccionada(null);
    };
    window.addEventListener('popstate', sincronizarRuta);
    const canonica = rutaHref(ruta.path);
    if (`${window.location.pathname}${window.location.search}` !== canonica) window.history.replaceState({}, '', canonica);
    return () => window.removeEventListener('popstate', sincronizarRuta);
  }, []);

  // Detecta cambios de copias y equipos mientras la sesión del panel está abierta.
  const cargarRef = useRef(copias.cargar);
  cargarRef.current = copias.cargar;
  const falloRef = useRef(copias.fallo);
  falloRef.current = copias.fallo;
  const vistaActual = useRef(vista);
  vistaActual.current = vista;
  useEffect(() => {
    if (!sesion) return undefined;
    let ultima = null;
    let comprobando = false;
    let activa = true;
    const comprobar = async () => {
      if (!activa || document.hidden || comprobando) return;
      comprobando = true;
      try {
        const d = await api('novedades');
        const clave = JSON.stringify([d.m, d.staging, d.ingesta, d.ausentes, d.eventos, d.alertas]);
        if (ultima === null) {
          ultima = clave;
          return;
        }
        const cambio = clave !== ultima;
        if (cambio) {
          ultima = clave;
          setActualizacionEquipos((actual) => actual + 1);
        }
        if (vistaActual.current === 'copias' && (cambio || falloRef.current)) cargarRef.current();
      } catch { /* se reintenta en el siguiente ciclo */ }
      finally { comprobando = false; }
    };
    comprobar();
    const t = setInterval(comprobar, 15000);
    return () => {
      activa = false;
      clearInterval(t);
    };
  }, [sesion]);

  const { data } = copias;
  const n = useMemo(() => contadores(data, f.horas), [data, f.horas]);
  const rep = useMemo(() => repeticiones(data), [data]);
  const rows = useMemo(() => {
    const q = norm(f.cli.trim());
    const ordenados = data.filter((c) => {
      const sinCopia = c.codigo === 'SIN_COPIA';
      if (sinCopia !== (filtro === 'sin')) return false;
      return (
        TEST[filtro](c, f.horas, rep) &&
        (!q || norm([c.cliente, c.equipo, c.software, c.programa, c.destino, c.desc_error, c.codigo].filter(Boolean).join(' ')).includes(q)) &&
        coincideTipo(c, f.tipo) &&
        (!f.software || c.software === f.software) &&
        (!f.desde || c.fecha.slice(0, 10) >= f.desde) &&
        (!f.hasta || c.fecha.slice(0, 10) <= f.hasta)
      );
    });
    const compararTexto = (a, b, key, direccion = 1) => String(a[key] || '').localeCompare(String(b[key] || ''), 'es', { sensitivity: 'base' }) * direccion;
    const ordenadosYAcumulados = ordenados.sort((a, b) => {
      if (f.orden === 'fecha-asc') return String(a.fecha).localeCompare(String(b.fecha)) || Number(a.id) - Number(b.id);
      if (f.orden === 'cliente-az') return compararTexto(a, b, 'cliente') || compararTexto(a, b, 'equipo');
      if (f.orden === 'cliente-za') return compararTexto(a, b, 'cliente', -1) || compararTexto(a, b, 'equipo');
      if (f.orden === 'equipo-az') return compararTexto(a, b, 'equipo') || compararTexto(a, b, 'cliente');
      if (f.orden === 'equipo-za') return compararTexto(a, b, 'equipo', -1) || compararTexto(a, b, 'cliente');
      return String(b.fecha).localeCompare(String(a.fecha)) || Number(b.id) - Number(a.id);
    });
    return acumularErrores(ordenadosYAcumulados);
  }, [data, filtro, f, rep]);
  const softwaresCopia = useMemo(() => [...new Set(data.map((c) => c.software).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es')), [data]);
  const paginaCopias = usePagination(rows, 20, `${filtro}|${f.cli}|${f.tipo}|${f.software}|${f.desde}|${f.hasta}|${f.orden}`);

  const abrir = useCallback((id) => {
    setCopiaSeleccionada(Number(id));
    navegar(`/copias/${id}`);
  }, [navegar]);
  const cerrar = useCallback(() => {
    setCopiaSeleccionada(null);
    navegar('/copias');
  }, [navegar]);
  const abrirEquipoDeCopia = useCallback((copia) => {
    if (!copia?.equipo_id) return;
    const cliente = copia.cliente_id || 'sin-asignar';
    navegar(`/clientes/${encodeURIComponent(String(cliente))}/equipos/${copia.equipo_id}`);
  }, [navegar]);
  const abrirNotificacion = useCallback((notificacion) => {
    setNotificacionesAbiertas(false);
    if (notificacion.tipo === 'crash' || notificacion.tipo === 'windows') {
      navegar(`/eventos?equipo_id=${notificacion.equipo_id}`);
    } else if (notificacion.tipo === 'copias_repetidas' && notificacion.copia_id) {
      abrir(notificacion.copia_id);
    } else {
      const cliente = notificacion.cliente_id || 'sin-asignar';
      navegar(`/clientes/${encodeURIComponent(cliente)}/equipos/${notificacion.equipo_id}`);
    }
  }, [abrir, navegar]);
  const idCopiaAbierta = ruta.copiaId ?? copiaSeleccionada;
  const cur = data.find((c) => Number(c.id) === Number(idCopiaAbierta));
  return (
    <>
      <Header nombre={sesion ? sesion.nombre : ''} vista={vista} onNavegar={navegar}
        onVolver={volver} mostrarVolver={vista !== 'inicio' || Boolean(ruta.equipoId)}
        esAdmin={sesion?.rol === 'admin'}
        onCuenta={() => { setNotificacionesAbiertas(false); setCuentaAbierta(true); }}
        onNotificaciones={() => { setCuentaAbierta(false); setNotificacionesAbiertas(true); }} />
      <main>
        {!sesion && vista !== 'copias' ? estadoSesion : vista === 'inicio' ? (
          sesion ? (ruta.equipoId ? <Inicio key={`inicio-${ruta.equipoId}`} equipoId={ruta.equipoId} sesion={sesion}
            refreshSignal={actualizacionEquipos} onNavigate={navegar} />
            : <Resumen refreshSignal={actualizacionEquipos} onNavigate={navegar} />)
            : estadoSesion
        ) : vista === 'equipos' ? (
          <Equipos filtros={ruta.filtros} refreshSignal={actualizacionEquipos} onNavigate={navegar} />
        ) : vista === 'informes' ? (
          sesion ? <InformeCopias onNavigate={navegar} /> : estadoSesion
        ) : vista === 'por-clasificar' ? (
          sesion ? <PorClasificar copias={copias.data} cargando={copias.cargando} error={copias.fallo}
            onClasificar={copias.clasificarRevision} onRecargar={copias.cargar} /> : estadoSesion
        ) : vista === 'eventos' ? (
          sesion ? <Eventos key={`${vista}-${ruta.equipoId || 'todos'}`} equipoId={ruta.equipoId} /> : estadoSesion
        ) : vista === 'clientes' && sesion ? (
          <Clientes key={vista} sesion={sesion} refreshSignal={actualizacionEquipos}
            clienteId={ruta.clienteId} equipoId={ruta.equipoId} pestana={ruta.pestana} onNavigate={navegar} />
        ) : (
          <>
            <PageHeading id="copias-titulo" eyebrow="Operaciones"
              title={filtro === 'sin' ? 'Equipos sin copia recibida' : 'Historial de copias'}
              description={filtro === 'sin'
                ? 'Equipos vigilados sin copia en el último día programado.'
                : 'Resultados, fallos y copias por clasificar.'}
              actions={<span className="page-heading-status">{rows.length.toLocaleString('es-ES')} resultados</span>} />
            {copias.fallo && (
              <div className="errbox">
                No se pudieron cargar los datos: {copias.fallo}{' '}
                <button className="btn sec" onClick={copias.cargar}>Reintentar</button>
              </div>
            )}
            <Kpis n={n} total={data.length - n.sc} />
            <Filtros filtro={filtro} setFiltro={setFiltro} n={n} f={f} setF={setF} resultados={rows.length} total={data.length} softwares={softwaresCopia} />
            {copias.truncado && <div className="estado-alertas" role="status">Este intervalo contiene más de 3.000 copias; se muestran las 3.000 más recientes. Reduce el rango de fechas para ver el resto.</div>}
            <Tabla sinCopia={filtro === 'sin'} rows={paginaCopias.items} cargando={copias.cargando} error={copias.fallo}
              onOpen={abrir} rep={rep} horas={f.horas} claveEquipo={claveEquipo} />
            <Paginacion total={rows.length} pagina={paginaCopias.page} paginas={paginaCopias.pageCount}
              porPagina={paginaCopias.pageSize} onCambiar={paginaCopias.setPage} etiqueta="copias" />
          </>
        )}
      </main>
      {cur && vista === 'copias' && (
        <Detalle c={cur} onClose={cerrar} onRevisar={copias.revisar} onReabrir={copias.reabrir}
          onSeg={copias.seguimiento} onNote={copias.nota} onAbrirEquipo={abrirEquipoDeCopia} />
      )}
      {sesion && cuentaAbierta && <Usuarios sesion={sesion} onClose={() => setCuentaAbierta(false)} />}
      {sesion && notificacionesAbiertas && <Notificaciones onClose={() => setNotificacionesAbiertas(false)}
        onAbrir={abrirNotificacion} />}
    </>
  );
}
