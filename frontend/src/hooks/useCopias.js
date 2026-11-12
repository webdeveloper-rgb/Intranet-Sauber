import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { api } from '../api';

// Carga las copias y expone las acciones del panel.
export function useCopias() {
  const [recientes, setRecientes] = useState([]);
  const [historial, setHistorial] = useState([]);
  const [hayMasHistorial, setHayMasHistorial] = useState(false);
  const [cargandoHistorial, setCargandoHistorial] = useState(false);
  const [cargando, setCargando] = useState(true);
  const [fallo, setFallo] = useState('');
  const cargaActual = useRef(0);
  const data = useMemo(() => [...recientes, ...historial], [recientes, historial]);

  const cargar = useCallback(async () => {
    const carga = ++cargaActual.current;
    try {
      const j = await api('list');
      if (carga !== cargaActual.current) return;
      setRecientes(j.copias);
      setHayMasHistorial(Boolean(j.hay_mas_historial));
      setFallo('');
    } catch (e) {
      if (carga === cargaActual.current) setFallo(e.message);
    } finally {
      if (carga === cargaActual.current) setCargando(false);
    }
  }, []);

  const cargarMasHistorial = useCallback(async () => {
    if (cargandoHistorial || !hayMasHistorial) return;
    setCargandoHistorial(true);
    try {
      const copias = [...recientes, ...historial].filter((c) => c.codigo !== 'SIN_COPIA');
      const cursor = copias.reduce((oldest, copia) => {
        if (!oldest || copia.cursor_fecha < oldest.cursor_fecha
          || (copia.cursor_fecha === oldest.cursor_fecha && Number(copia.id) < Number(oldest.id))) {
          return copia;
        }
        return oldest;
      }, null);
      const j = await api('history', cursor
        ? { cursor_fecha: cursor.cursor_fecha, cursor_id: cursor.id }
        : {});
      setHistorial((current) => {
        const byId = new Map(current.map((c) => [c.id, c]));
        j.copias.forEach((c) => byId.set(c.id, c));
        return [...byId.values()].sort((a, b) => {
          const dateOrder = b.cursor_fecha.localeCompare(a.cursor_fecha);
          return dateOrder || Number(b.id) - Number(a.id);
        });
      });
      setHayMasHistorial(Boolean(j.hay_mas_historial));
      const ultima = j.copias[j.copias.length - 1];
      return ultima ? { desde: ultima.fecha.slice(0, 10) } : null;
    } catch (e) {
      setFallo(e.message);
      return null;
    } finally {
      setCargandoHistorial(false);
    }
  }, [cargandoHistorial, hayMasHistorial, recientes, historial]);

  useEffect(() => {
    cargar();
  }, [cargar]);

  // Cada acción llama a la API y sustituye la copia con lo que devuelve el servidor
  const run = useCallback(async (action, body) => {
    try {
      const j = await api(action, body);
      const actualizar = (copias) => copias.map((c) => (c.id === j.copia.id ? j.copia : c));
      setRecientes(actualizar);
      setHistorial(actualizar);
      return true;
    } catch (e) {
      alert(e.message);
      return false;
    }
  }, []);

  return {
    data, cargando, fallo, cargar, cargarMasHistorial, hayMasHistorial, cargandoHistorial,
    revisar: (id, desc) => run('revisar', { id, desc }),
    reabrir: (id) => run('reabrir', { id }),
    seguimiento: (id, campos) => run('seguimiento', { id, ...campos }),
    nota: (id, texto) => run('nota', { id, texto }),
  };
}
