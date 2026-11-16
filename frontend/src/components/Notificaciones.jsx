import { useEffect, useState } from 'react';
import { api } from '../api';
import Paginacion from './Paginacion';

const POR_PAGINA = 25;
const TIPOS = [
  ['all', 'Todos los tipos'],
  ['disco', 'Salud del disco'],
  ['espacio', 'Espacio disponible'],
  ['bateria', 'Salud de batería'],
  ['crash', 'Caída o bloqueo de Windows'],
  ['windows', 'Otros eventos de Windows'],
  ['copias_repetidas', 'Fallos de copia'],
  ['salud', 'Otras alertas de salud'],
];
const NIVELES = [
  ['all', 'Todos los niveles'],
  ['aviso', 'Aviso'],
  ['advertencia', 'Advertencia'],
  ['error', 'Error'],
  ['critico', 'Crítico'],
];
const ETIQUETAS_NIVEL = Object.fromEntries(NIVELES.slice(1));

function fechaHora(value) {
  if (!value) return 'Fecha desconocida';
  const parsed = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(parsed.getTime())) return String(value);
  return new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' }).format(parsed);
}

export default function Notificaciones({ onClose, onAbrir }) {
  const [buscar, setBuscar] = useState('');
  const [q, setQ] = useState('');
  const [tipo, setTipo] = useState('all');
  const [nivel, setNivel] = useState('all');
  const [pagina, setPagina] = useState(1);
  const [resultado, setResultado] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState('');
  const [actualizar, setActualizar] = useState(0);

  useEffect(() => {
    const timer = setTimeout(() => {
      setPagina(1);
      setQ(buscar.trim());
    }, 250);
    return () => clearTimeout(timer);
  }, [buscar]);

  useEffect(() => {
    let activa = true;
    setCargando(true);
    setError('');
    api(`notificaciones&page=${pagina}&q=${encodeURIComponent(q)}&tipo=${encodeURIComponent(tipo)}&nivel=${encodeURIComponent(nivel)}`)
      .then((data) => {
        if (!activa) return;
        setResultado({
          notificaciones: Array.isArray(data.notificaciones) ? data.notificaciones : [],
          total: Number(data.total) || 0,
          pagina: Number(data.pagina) || pagina,
          porPagina: Number(data.por_pagina) || POR_PAGINA,
        });
      })
      .catch((e) => {
        if (activa) setError(e.message || 'No se pudieron cargar las notificaciones.');
      })
      .finally(() => {
        if (activa) setCargando(false);
      });
    return () => { activa = false; };
  }, [pagina, q, tipo, nivel, actualizar]);

  useEffect(() => {
    const cerrarConEscape = (event) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', cerrarConEscape);
    return () => window.removeEventListener('keydown', cerrarConEscape);
  }, [onClose]);

  const notificaciones = resultado?.notificaciones || [];
  const total = resultado?.total || 0;
  const paginas = Math.max(1, Math.ceil(total / (resultado?.porPagina || POR_PAGINA)));

  return (
    <>
      <div className="ov" onClick={onClose} />
      <aside className="notificaciones-drawer" role="dialog" aria-modal="true" aria-labelledby="notificaciones-titulo">
        <button className="x" onClick={onClose} aria-label="Cerrar notificaciones">×</button>
        <div className="notificaciones-heading">
          <span className="notificaciones-eyebrow">Centro de incidencias</span>
          <h2 id="notificaciones-titulo">Notificaciones</h2>
          <p>Alertas activas, eventos de Windows según el modo de cada equipo y fallos de copias de las últimas 24 horas.</p>
        </div>

        <div className="notificaciones-filtros">
          <label>
            <span>Buscar</span>
            <input type="search" value={buscar} onChange={(event) => setBuscar(event.target.value)}
              placeholder="Cliente, equipo o descripción" />
          </label>
          <label>
            <span>Tipo</span>
            <select value={tipo} onChange={(event) => { setTipo(event.target.value); setPagina(1); }}>
              {TIPOS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </label>
          <label>
            <span>Nivel</span>
            <select value={nivel} onChange={(event) => { setNivel(event.target.value); setPagina(1); }}>
              {NIVELES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </label>
        </div>

        <div className="notificaciones-resumen">
          <span>{cargando ? 'Actualizando…' : `${total} notificación${total === 1 ? '' : 'es'}`}</span>
          <button className="btn sec" onClick={() => setActualizar((value) => value + 1)} disabled={cargando}>
            Actualizar
          </button>
        </div>

        {error && (
          <div className="errbox" role="alert">
            {error}{' '}
            <button className="btn sec" onClick={() => setActualizar((value) => value + 1)}>Reintentar</button>
          </div>
        )}

        <div className="notificaciones-lista" aria-live="polite" aria-busy={cargando}>
          {!cargando && !error && notificaciones.length === 0 && (
            <p className="notificaciones-vacio">No hay notificaciones que coincidan con estos filtros.</p>
          )}
          {notificaciones.map((item) => {
            const severidad = NIVELES.some(([value]) => value === item.nivel) ? item.nivel : 'aviso';
            const etiquetaNivel = ETIQUETAS_NIVEL[severidad] || 'Aviso';
            return (
              <button
                className={`notificacion-item notificacion-${severidad}`}
                key={item.id}
                onClick={() => onAbrir(item)}
                aria-label={`${etiquetaNivel}: ${item.titulo}. Equipo ${item.equipo}. Abrir detalle.`}
              >
                <span className={`notificacion-nivel nivel-${severidad}`}>{etiquetaNivel}</span>
                <strong>{item.titulo}</strong>
                <span className="notificacion-detalle">{item.detalle || 'Sin descripción disponible.'}</span>
                <span className="notificacion-meta">{item.cliente} · {item.equipo}</span>
                <time dateTime={item.fecha}>{fechaHora(item.fecha)}</time>
              </button>
            );
          })}
        </div>

        {!error && <Paginacion total={total} pagina={pagina} paginas={paginas}
          porPagina={resultado?.porPagina || POR_PAGINA} onCambiar={setPagina} etiqueta="notificaciones" />}
      </aside>
    </>
  );
}
