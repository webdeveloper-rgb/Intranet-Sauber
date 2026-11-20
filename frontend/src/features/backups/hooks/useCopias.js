/**
 * Hook de carga, estado y acciones sobre copias mediante la API del panel.
 */
import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { api } from '../../../shared/api/client';

// Carga las copias y expone las acciones del panel.
export function useCopias(desde = '', hasta = '', enabled = true) {
  const [recientes, setRecientes] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [fallo, setFallo] = useState('');
  const [truncado, setTruncado] = useState(false);
  const cargaActual = useRef(0);
  const filtrosActuales = useRef({ desde: '', hasta: '' });
  const data = useMemo(() => recientes, [recientes]);

  const cargar = useCallback(async (desde = filtrosActuales.current.desde, hasta = filtrosActuales.current.hasta) => {
    if (typeof desde !== 'string') desde = filtrosActuales.current.desde;
    if (typeof hasta !== 'string') hasta = filtrosActuales.current.hasta;
    filtrosActuales.current = { desde, hasta };
    const carga = ++cargaActual.current;
    setCargando(true);
    try {
      const query = `list${desde ? `&desde=${encodeURIComponent(desde)}` : ''}${hasta ? `&hasta=${encodeURIComponent(hasta)}` : ''}`;
      const j = await api(query);
      if (carga !== cargaActual.current) return;
      setRecientes(j.copias);
      setTruncado(Boolean(j.truncado));
      setFallo('');
    } catch (e) {
      if (carga === cargaActual.current) setFallo(e.message);
    } finally {
      if (carga === cargaActual.current) setCargando(false);
    }
  }, []);

  useEffect(() => {
    if (!enabled) {
      setCargando(false);
      return undefined;
    }
    setCargando(true);
    cargar(desde, hasta);
    return () => { cargaActual.current += 1; };
  }, [cargar, desde, hasta, enabled]);

  // Cada acción llama a la API y sustituye la copia con lo que devuelve el servidor
  const run = useCallback(async (action, body) => {
    try {
      const j = await api(action, body);
      const actualizadas = new Set(j.actualizadas || []);
      const actualizar = (copias) => copias.map((c) => {
        if (c.id === j.copia.id) return j.copia;
        if (actualizadas.has(c.id)) return { ...c, estado: 'OK', severidad: 'normal', revisado: 1, seg: 'cerrado', recordar: null };
        return c;
      });
      setRecientes(actualizar);
      return true;
    } catch (e) {
      alert(e.message);
      return false;
    }
  }, []);

  const clasificarRevision = useCallback(async (grupo, cambios) => {
    try {
      const j = await api('clasificar_revision', { grupo, ...cambios });
      const actualizadas = new Set(j.actualizadas || []);
      setRecientes((copias) => copias.map((c) => (
        actualizadas.has(c.id) ? { ...c, ...j.clasificacion } : c
      )));
      return true;
    } catch (e) {
      alert(e.message);
      return false;
    }
  }, []);

  return {
    data, cargando, fallo, truncado, cargar,
    revisar: (id, desc) => run('revisar', { id, desc }),
    reabrir: (id) => run('reabrir', { id }),
    seguimiento: (id, campos) => run('seguimiento', { id, ...campos }),
    nota: (id, texto) => run('nota', { id, texto }),
    clasificarRevision,
  };
}
