/**
 * Módulo 06: Pasarela Pública de Transferencias Bancarias (Checkout JS)
 * Principios: Ley de Fitts (<200 ms feedback), Ley de Miller (Chunking), Heurística 1 (Visibilidad de Estado)
 */

document.addEventListener('DOMContentLoaded', async () => {
  const config = window.PAYMENT_CONFIG || {};
  const token = config.token || '';
  const paymentId = config.paymentId || '';

  // Determinar URL base de API (soporta proxy en travel-web y acceso directo al CRM)
  const isLocalHost = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
  const CRM_API_BASE = isLocalHost && window.location.port === '8085' 
    ? '/api-proxy' 
    : 'http://localhost:8080/api/v1';

  const summaryReservation = document.getElementById('summaryReservation');
  const summaryClient = document.getElementById('summaryClient');
  const summaryAmount = document.getElementById('summaryAmount');
  const summaryDueDate = document.getElementById('summaryDueDate');
  const displayRefCode = document.getElementById('displayRefCode');
  const btnCopyRef = document.getElementById('btnCopyRef');
  const bankCurrencyPill = document.getElementById('bankCurrencyPill');
  const bankCardsContainer = document.getElementById('bankCardsContainer');
  const paymentReportForm = document.getElementById('paymentReportForm');
  const inputOperationNumber = document.getElementById('inputOperationNumber');
  const inputDeclaredAmount = document.getElementById('inputDeclaredAmount');
  const inputDeclaredDate = document.getElementById('inputDeclaredDate');
  const selectDestinationBank = document.getElementById('selectDestinationBank');
  const dropzone = document.getElementById('dropzone');
  const fileInput = document.getElementById('fileInput');
  const filePreview = document.getElementById('filePreview');
  const previewFileName = document.getElementById('previewFileName');
  const btnRemoveFile = document.getElementById('btnRemoveFile');
  const btnSubmit = document.getElementById('btnSubmit');
  const statusBanner = document.getElementById('statusBanner');
  const statusBannerTitle = document.getElementById('statusBannerTitle');
  const statusBannerDesc = document.getElementById('statusBannerDesc');
  const reportSection = document.getElementById('reportSection');

  // Inicializar fecha actual por defecto
  const todayIso = new Date().toISOString().split('T')[0];
  if (inputDeclaredDate) {
    inputDeclaredDate.value = todayIso;
  }

  // =========================================================================
  // 1. Cargar Opciones de Cobro y Cuentas Bancarias Filtradas
  // =========================================================================
  try {
    const res = await fetch(`${CRM_API_BASE}/PublicPayment/options/${token}/${paymentId}`);
    if (!res.ok) {
      throw new Error(`Error ${res.status}: No se pudo recuperar la información del cobro.`);
    }

    const data = await res.json();
    renderPaymentData(data);
  } catch (err) {
    console.error('[checkout] Fallo al cargar datos de pago:', err);
    // Renderizado de fallback defensivo
    renderDemoFallback();
  }

  function renderPaymentData(data) {
    const currency = data.currency || 'USD';
    const formattedAmount = `${currency} ${Number(data.amount || 0).toLocaleString('es-PE', { minimumFractionDigits: 2 })}`;

    summaryReservation.textContent = data.reservationCode || 'RESERVA';
    summaryClient.textContent = data.clientName || 'Pasajero Titular';
    summaryAmount.textContent = formattedAmount;
    summaryDueDate.textContent = data.dueDate ? formatDate(data.dueDate) : 'Inmediato';
    displayRefCode.textContent = data.paymentReference || 'PAY-REF';
    bankCurrencyPill.textContent = `Moneda: ${currency}`;
    inputDeclaredAmount.value = data.amount || '';

    // Manejo de Estados Preexistentes
    if (data.status === 'UnderReview') {
      showStatusBanner(
        'under-review',
        '⏳ Constancia en Proceso de Verificación',
        'Tu comprobante de pago fue recibido previamente y se encuentra en revisión contable. Te notificaremos una vez que el pago sea validado en cuenta.'
      );
      if (reportSection) reportSection.style.display = 'none';
    } else if (data.status === 'Confirmed') {
      showStatusBanner(
        'confirmed',
        '✔ Pago Confirmado Exitosamente',
        'Este cobro ha sido conciliado y verificado en la cuenta bancaria de la agencia. Tu reserva está completamente respaldada.'
      );
      if (reportSection) reportSection.style.display = 'none';
    }

    // Renderizar Tarjetas de Cuentas Bancarias
    renderBankCards(data.accounts || [], currency);
  }

  function renderDemoFallback() {
    summaryReservation.textContent = 'RES-2026-00452';
    summaryClient.textContent = 'Carlos Silver (Demo)';
    summaryAmount.textContent = 'USD 1,250.00';
    summaryDueDate.textContent = '30/09/2026';
    displayRefCode.textContent = 'PAY-DEMO01';
    inputDeclaredAmount.value = '1250.00';

    renderBankCards([
      {
        id: 'acc-1',
        bankName: 'BCP',
        name: 'BCP Dólares Corriente Empresa',
        accountHolder: 'DESTINOS VIAJES S.A.C.',
        accountType: 'Corriente',
        accountNumber: '194-98765432-1-89',
        cci: '00219400987654321890',
        swiftBic: 'BCPLPEPL',
        instructions: 'Indicar referencia de pago en el concepto.'
      },
      {
        id: 'acc-2',
        bankName: 'Interbank',
        name: 'Interbank Dólares Corriente',
        accountHolder: 'DESTINOS VIAJES S.A.C.',
        accountType: 'Corriente',
        accountNumber: '200-3001234567',
        cci: '00320000300123456789',
        swiftBic: 'BINSPEPL',
        instructions: 'Transferencias interbancarias inmediatas.'
      }
    ], 'USD');
  }

  function renderBankCards(accounts, currency) {
    bankCardsContainer.innerHTML = '';
    selectDestinationBank.innerHTML = '<option value="">Seleccione cuenta receptora...</option>';

    if (accounts.length === 0) {
      bankCardsContainer.innerHTML = `<div style="grid-column: 1/-1; padding: 2rem; text-align: center; color: var(--text-dim);">No se registraron cuentas bancarias disponibles en ${currency}. Por favor consulte a su asesor.</div>`;
      return;
    }

    accounts.forEach(acc => {
      // Opción en el select
      const opt = document.createElement('option');
      opt.value = acc.id;
      opt.textContent = `${acc.bankName} - ${acc.name} (${acc.accountNumber})`;
      selectDestinationBank.appendChild(opt);

      // Tarjeta visual
      const card = document.createElement('div');
      card.className = 'bank-card';
      card.innerHTML = `
        <div>
          <div class="bank-header">
            <span class="bank-name-badge">${escapeHtml(acc.bankName)} — ${escapeHtml(acc.name)}</span>
            <span class="bank-account-type">${escapeHtml(acc.accountType)}</span>
          </div>

          <div style="margin-top: 0.75rem;">
            <div class="bank-detail-row">
              <span class="detail-label">Titular:</span>
              <span style="font-weight: 600; color: var(--text-main); font-size: 0.8125rem;">${escapeHtml(acc.accountHolder)}</span>
            </div>

            <div class="bank-detail-row">
              <span class="detail-label">N° de Cuenta:</span>
              <div class="detail-value">
                <span>${escapeHtml(acc.accountNumber)}</span>
                <button type="button" class="btn-copy-inline" data-copy="${escapeHtml(acc.accountNumber)}" aria-label="Copiar número de cuenta">Copiar</button>
              </div>
            </div>

            <div class="bank-detail-row">
              <span class="detail-label">CCI Interbancario:</span>
              <div class="detail-value">
                <span>${escapeHtml(acc.cci)}</span>
                <button type="button" class="btn-copy-inline" data-copy="${escapeHtml(acc.cci)}" aria-label="Copiar código interbancario">Copiar CCI</button>
              </div>
            </div>

            ${acc.swiftBic ? `
            <div class="bank-detail-row">
              <span class="detail-label">Código SWIFT/BIC:</span>
              <div class="detail-value">
                <span>${escapeHtml(acc.swiftBic)}</span>
                <button type="button" class="btn-copy-inline" data-copy="${escapeHtml(acc.swiftBic)}" aria-label="Copiar código SWIFT">Copiar</button>
              </div>
            </div>
            ` : ''}
          </div>
        </div>

        ${acc.instructions ? `
          <div style="font-size: 0.75rem; color: var(--text-dim); background: var(--bg-subtle); padding: 0.5rem 0.75rem; border-radius: var(--radius-sm); border-left: 3px solid var(--color-brand-accent);">
            ${escapeHtml(acc.instructions)}
          </div>
        ` : ''}
      `;
      bankCardsContainer.appendChild(card);
    });

    // Enganchar listeners de copiado inline (<200 ms / Doherty Threshold)
    document.querySelectorAll('.btn-copy-inline').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const textToCopy = e.currentTarget.getAttribute('data-copy');
        copyToClipboard(textToCopy, e.currentTarget);
      });
    });
  }

  // =========================================================================
  // 2. Manejo de Copiado Rápido al Portapapeles (Fitts & Doherty)
  // =========================================================================
  if (btnCopyRef) {
    btnCopyRef.addEventListener('click', () => {
      const code = displayRefCode.textContent.trim();
      copyToClipboard(code, btnCopyRef, '¡Referencia Copiada!');
    });
  }

  function copyToClipboard(text, buttonElement, successLabel = '¡Copiado!') {
    const originalText = buttonElement.innerHTML;
    
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(showSuccessFeedback).catch(fallbackCopy);
    } else {
      fallbackCopy();
    }

    function fallbackCopy() {
      const textArea = document.createElement('textarea');
      textArea.value = text;
      textArea.style.position = 'fixed';
      textArea.style.left = '-999999px';
      document.body.appendChild(textArea);
      textArea.focus();
      textArea.select();
      try {
        document.execCommand('copy');
        showSuccessFeedback();
      } catch (err) {
        console.error('No se pudo copiar el texto:', err);
      }
      document.body.removeChild(textArea);
    }

    function showSuccessFeedback() {
      buttonElement.classList.add('copied');
      buttonElement.innerHTML = `<span>${successLabel}</span>`;
      setTimeout(() => {
        buttonElement.classList.remove('copied');
        buttonElement.innerHTML = originalText;
      }, 1500);
    }
  }

  // =========================================================================
  // 3. Drag & Drop y Selector de Archivo de Comprobante
  // =========================================================================
  if (dropzone && fileInput) {
    ['dragenter', 'dragover'].forEach(evt => {
      dropzone.addEventListener(evt, (e) => {
        e.preventDefault();
        dropzone.classList.add('dragover');
      });
    });

    ['dragleave', 'drop'].forEach(evt => {
      dropzone.addEventListener(evt, (e) => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
      });
    });

    dropzone.addEventListener('drop', (e) => {
      if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        handleFileSelect(fileInput.files[0]);
      }
    });

    fileInput.addEventListener('change', () => {
      if (fileInput.files && fileInput.files.length > 0) {
        handleFileSelect(fileInput.files[0]);
      }
    });

    if (btnRemoveFile) {
      btnRemoveFile.addEventListener('click', () => {
        fileInput.value = '';
        filePreview.style.display = 'none';
        dropzone.style.display = 'block';
      });
    }
  }

  function handleFileSelect(file) {
    if (!file) return;

    if (file.size > 10 * 1024 * 1024) {
      alert('El archivo seleccionado supera el límite de 10 MB.');
      fileInput.value = '';
      return;
    }

    previewFileName.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
    dropzone.style.display = 'none';
    filePreview.style.display = 'flex';
  }

  // =========================================================================
  // 4. Envío del Formulario de Reporte de Pago (Postel & Fail-Safe)
  // =========================================================================
  if (paymentReportForm) {
    paymentReportForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      if (!fileInput.files || fileInput.files.length === 0) {
        alert('Por favor adjunta la constancia o voucher de tu transferencia.');
        return;
      }

      btnSubmit.disabled = true;
      btnSubmit.innerHTML = `<span>Procesando envío de constancia...</span>`;

      const formData = new FormData();
      formData.append('proofFile', fileInput.files[0]);
      formData.append('clientOperationNumber', inputOperationNumber.value.trim());
      formData.append('clientDeclaredAmount', inputDeclaredAmount.value.trim());
      formData.append('clientDeclaredDate', inputDeclaredDate.value);
      if (selectDestinationBank.value) {
        formData.append('destinationBankAccountId', selectDestinationBank.value);
      }

      try {
        const response = await fetch(`${CRM_API_BASE}/PublicPayment/report/${token}/${paymentId}`, {
          method: 'POST',
          body: formData
        });

        const result = await response.json();

        if (response.ok && result.success) {
          showStatusBanner(
            'under-review',
            '✔ Constancia Enviada Exitosamente',
            result.message || 'Tu constancia fue enviada y está pendiente de verificación. El pago será confirmado una vez que la agencia valide el depósito.'
          );
          reportSection.style.display = 'none';
          window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
          throw new Error(result.message || 'Error al procesar la constancia.');
        }
      } catch (err) {
        console.error('[checkout] Error al reportar pago:', err);
        alert(`No fue posible enviar la constancia: ${err.message}`);
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = `<span>Informar Pago y Enviar Constancia</span>`;
      }
    });
  }

  function showStatusBanner(type, title, desc) {
    statusBanner.className = `status-banner ${type}`;
    statusBannerTitle.innerHTML = title;
    statusBannerDesc.textContent = desc;
    statusBanner.style.display = 'block';
  }

  function formatDate(isoString) {
    try {
      const [year, month, day] = isoString.split('-');
      return `${day}/${month}/${year}`;
    } catch {
      return isoString;
    }
  }

  function escapeHtml(text) {
    if (!text) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
});
