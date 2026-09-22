/**
 * Interacciones de UI del Expediente Digital de Viaje
 * Heurística 5 (Prevención de errores) & Heurística 6 (Reconocimiento antes que recuerdo)
 */
document.addEventListener('DOMContentLoaded', () => {
  // 1. Detección de flag ?print=true para compilador headless de Puppeteer
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('print') === 'true') {
    document.body.classList.add('print-mode');
  }

  // 2. Copiado rápido de códigos PNR y Vouchers al hacer click
  document.querySelectorAll('.meta-code, .pnr-pill').forEach((el) => {
    el.setAttribute('title', 'Click para copiar');
    el.style.cursor = 'pointer';

    el.addEventListener('click', async () => {
      const text = el.textContent.trim();
      try {
        await navigator.clipboard.writeText(text);
        const originalTitle = el.getAttribute('title');
        el.setAttribute('title', '¡Copiado al portapapeles!');
        
        // Feedback visual temporal
        const originalBg = el.style.backgroundColor;
        el.style.backgroundColor = '#dcfce7';
        setTimeout(() => {
          el.style.backgroundColor = originalBg;
          el.setAttribute('title', originalTitle);
        }, 1500);
      } catch (err) {
        // Fallback silencioso si el navegador restringe el portapapeles
      }
    });
  });

  // 3. Manejo de descarga del PDF desde la UI
  const downloadBtn = document.getElementById('btnDownloadPdf');
  if (downloadBtn) {
    downloadBtn.addEventListener('click', () => {
      // Si estamos en un navegador que soporta impresión directa
      window.print();
    });
  }
});
