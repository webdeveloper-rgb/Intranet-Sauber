import logoUrl from '../../assets/logo_sauber.png';

let logoDataUrl;

async function cargarLogo() {
  if (logoDataUrl) return logoDataUrl;
  const response = await fetch(logoUrl);
  if (!response.ok) throw new Error('No se pudo cargar el logo para el PDF.');
  const blob = await response.blob();
  logoDataUrl = await new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(new Error('No se pudo preparar el logo para el PDF.'));
    reader.readAsDataURL(blob);
  });
  return logoDataUrl;
}

export async function agregarLogoPdf(doc, x, y, width = 46) {
  const data = await cargarLogo();
  doc.addImage(data, 'PNG', x, y, width, width / (1466 / 363));
}
