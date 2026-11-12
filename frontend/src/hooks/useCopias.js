/**
 * Hook de carga, estado y acciones sobre copias mediante la API del panel.
 */
import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { api } from '../api';

// Carga las copias y expone las acciones del panel.
export function useCopias() {
  const [recientes, setRecientes] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [fallo, setFallo] = useState('');
  const cargaActual = useRef(0);
  const data = useMemo(() => recientes, [recientes]);

  const cargar = useCallback(async () => {
    const carga = ++cargaActual.current;
    try {
      const j = await api('list');
      if (carga !== cargaActual.current) return;
      setRecientes(j.copias);
      setFallo('');
    } catch (e) {
      if (carga === cargaActual.current) setFallo(e.message);
    } finally {
      if (carga === cargaActual.current) setCargando(false);
    }
  }, []);

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
    data, cargando, fallo, cargar,
    revisar: (id, desc) => run('revisar', { id, desc }),
    reabrir: (id) => run('reabrir', { id }),
    seguimiento: (id, campos) => run('seguimiento', { id, ...campos }),
    nota: (id, texto) => run('nota', { id, texto }),
  };
}
