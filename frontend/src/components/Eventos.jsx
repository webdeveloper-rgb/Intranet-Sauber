import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../api';
import Paginacion from './Paginacion';

const POR_PAGINA = 50;
const niveles = [
  ['all', 'Todos los niveles'],
  ['Critical', 'Crítico'],
  ['Error', 'Error'],
];

function fechaHora(value) {
  if (!value) return '—';
  const parsed = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(parsed.getTime())) return value;
  return new Intl.DateTimeFormat('es-ES', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(parsed);
}

function Nivel({ nivel }) {
  const critical = nivel === 'Critical';
  const etiqueta = critical ? 'Crítico' : nivel === 'Error' ? 'Error' : nivel || '—';
  return <span className={`evento-nivel ${critical ? 'critical' : 'error'}`}>{etiqueta}</span>;
}

export default function Eventos({ equipoId = null }) {
  const [buscar, setBuscar] = useState('');
  const [q, setQ] = useState('');
  const [nivel, setNivel] = useState('all');
  const [pagina, setPagina] = useState(1);
  const [resultado, setResultado] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState('');
  const parametrosRef = useRef({ page: pagina, q, nivel, equipoId });
  parametrosRef.current = { page: pagina, q, nivel, equipoId };
  const enCursoRef = useRef(false);
  const pendienteRef = useRef(false);
  const activaRef = useRef(true);
  const cargarRef = useRef(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setPagina(1);
      setQ(buscar.trim());
    }, 250);
    return () => clearTimeout(timer);
  }, [buscar]);

  const cargar = useCallback(async () => {
    if (!activaRef.current) return;
    if (enCursoRef.current) {
      pendienteRef.current = true;
      return;
    }

    enCursoRef.current = true;
    const parametros = { ...parametrosRef.current };
    const clave = JSON.stringify(parametros);
    setCargando(true);
    try {
      const data = await api(
        `monitor_eventos&page=${parametros.page}&q=${encodeURIComponent(parametros.q)}&nivel=${encodeURIComponent(parametros.nivel)}${parametros.equipoId ? `&equipo_id=${parametros.equipoId}` : ''}`,
      );
      if (!activaRef.current) return;
      if (clave === JSON.stringify(parametrosRef.current)) {
        setResultado({
          eventos: Array.isArray(data.eventos) ? data.eventos : [],
          total: Number(data.total) || 0,
          pagina: Number(data.pagina) || parametros.page,
          porPagina: Number(data.por_pagina) || POR_PAGINA,
        });
        setError('');
      } else {
        pendienteRef.current = true;
      }
    } catch (e) {
      if (activaRef.current && clave === JSON.stringify(parametrosRef.current)) {
        setError(e.message || 'No se pudieron cargar los eventos.');
      } else if (activaRef.current) {
        pendienteRef.current = true;
      }
    } finally {
      enCursoRef.current = false;
      if (activaRef.current) {
        setCargando(false);
        if (pendienteRef.current) {
          pendienteRef.current = false;
          cargarRef.current?.();
        }
      }
    }
  }, [equipoId]);
  cargarRef.current = cargar;

  useEffect(() => {
    activaRef.current = true;
    return () => {
      activaRef.current = false;
    };
  }, []);

  useEffect(() => {
    cargar();
  }, [cargar, pagina, q, nivel]);

  useEffect(() => {
    const timer = setInterval(() => cargarRef.current?.(), 60_000);
    return () => clearInterval(timer);
  }, []);

  const eventos = resultado?.eventos || [];
  const total = resultado?.total || 0;
  const paginas = Math.max(1, Math.ceil(total / (resultado?.porPagina || POR_PAGINA)));
  const equipoNombre = eventos[0]?.equipo || `equipo #${equipoId}`;

  return (
    <section className="eventos-page" aria-labelledby="eventos-title">
      <div className="eventos-heading">
        <div>
          <span className="eyebrow">Monitor de equipos</span>
          <h2 id="eventos-title">{equipoId ? `Eventos de ${equipoNombre}` : 'Eventos de Windows'}</h2>
          <p>{equipoId
            ? 'Solo se muestran los eventos Críticos y de Error de este equipo recibidos durante los últimos 30 días.'
            : 'Eventos de nivel Crítico y Error de los canales System y Application, recibidos durante los últimos 30 días.'}</p>
        </div>
        <div className="eventos-actualizacion" aria-live="polite">
          <span className={`eventos-indicador${cargando ? ' actualizando' : ''}`} aria-hidden="true" />
          {cargando ? 'Actualizando…' : 'Actualizado automáticamente cada minuto'}
        </div>
      </div>

      <div className="eventos-controles">
        <label className="eventos-busqueda" htmlFor="eventos-buscar">
          <span>Buscar</span>
          <input
            id="eventos-buscar"
            type="search"
            value={buscar}
            onChange={(event) => setBuscar(event.target.value)}
            placeholder="Cliente, equipo, origen o mensaje"
          />
        </label>
        <label className="eventos-nivel" htmlFor="eventos-nivel">
          <span>Nivel</span>
          <select
            id="eventos-nivel"
            value={nivel}
            onChange={(event) => {
              setNivel(event.target.value);
              setPagina(1);
            }}
          >
            {niveles.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
        <p className="eventos-recuento" aria-live="polite">
          {resultado === null && cargando ? 'Cargando resultados…' : `${total.toLocaleString('es-ES')} evento${total === 1 ? '' : 's'}`}
        </p>
      </div>

      {error && (
        <div className="errbox eventos-error" role="alert">
          No se pudieron cargar los eventos: {error}{' '}
          <button className="btn sec" onClick={cargar}>Reintentar</button>
        </div>
      )}

      {resultado === null && cargando ? (
        <div className="eventos-estado" role="status">Cargando eventos…</div>
      ) : eventos.length === 0 ? (
        <div className="eventos-estado">
          <strong>{error ? 'No hay resultados disponibles' : 'No se encontraron eventos'}</strong>
          <span>{error ? 'Comprueba la conexión e inténtalo de nuevo.' : 'Prueba a cambiar los filtros o la búsqueda.'}</span>
        </div>
      ) : (
        <div className="wrap eventos-tabla-wrap" role="region"
          aria-label={equipoId ? `Eventos de Windows de ${equipoNombre}` : 'Eventos de Windows'} tabIndex="0">
          <table className="eventos-table">
            <thead>
              <tr>
                <th scope="col">Fecha y hora</th>
                <th scope="col">Cliente</th>
                <th scope="col">Equipo</th>
                <th scope="col">Nivel</th>
                <th scope="col">Canal</th>
                <th scope="col">Origen</th>
                <th scope="col">Event ID</th>
                <th scope="col">Mensaje</th>
              </tr>
            </thead>
            <tbody>
              {eventos.map((evento) => (
                <tr key={evento.id}>
                  <td className="evento-fecha">{fechaHora(evento.fecha)}</td>
                  <td className="evento-cliente">{evento.cliente || '—'}</td>
                  <td className="evento-equipo">{evento.equipo || '—'}</td>
                  <td><Nivel nivel={evento.nivel} /></td>
                  <td className="evento-canal">{evento.canal || '—'}</td>
                  <td className="evento-origen">{evento.origen || '—'}</td>
                  <td className="evento-id">{evento.event_id || '—'}</td>
                  <td className="evento-mensaje">{evento.mensaje || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Paginacion
        total={total}
        pagina={resultado?.pagina || pagina}
        paginas={paginas}
        porPagina={resultado?.porPagina || POR_PAGINA}
        onCambiar={setPagina}
        etiqueta="eventos"
      />
    </section>
  );
}
