/**
 * Hook reutilizable para paginar colecciones locales y reiniciar pagina al cambiar filtros.
 */
import { useEffect, useState } from 'react';

export function usePagination(items, pageSize = 20, resetKey = '') {
  const [page, setPage] = useState(1);
  const pageCount = Math.max(1, Math.ceil(items.length / pageSize));
  const currentPage = Math.min(page, pageCount);

  useEffect(() => { setPage(1); }, [resetKey]);
  useEffect(() => {
    if (currentPage !== page) setPage(currentPage);
  }, [currentPage, page]);

  return {
    items: items.slice((currentPage - 1) * pageSize, currentPage * pageSize),
    page: currentPage,
    pageCount,
    setPage,
    pageSize,
  };
}
