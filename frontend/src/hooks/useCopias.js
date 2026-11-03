import { useState, useEffect, useCallback } from 'react';
import { api } from '../api';

// Carga las copias, las refresca cada minuto y expone las acciones del panel.
export function useCopias() {
  const [data, setData] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [fallo, setFallo] = useState('');

  const cargar = useCallback(async () => {
    try {
      const j = await api('list');
      setData(j.copias);
      setFallo('');
    } catch (e) {
      setFallo(e.message);
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => {
    cargar();
    const t = setInterval(cargar, 60000);
    return () => clearInterval(t);
  }, [cargar]);

  // Cada acción llama a la API y sustituye la copia con lo que devuelve el servidor
  const run = useCallback(async (action, body) => {
    try {
      const j = await api(action, body);
      setData((d) => d.map((c) => (c.id === j.copia.id ? j.copia : c)));
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
