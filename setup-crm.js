const fs = require('fs');
const path = require('path');

const basePath = path.join(__dirname, 'custom', 'Espo', 'Custom', 'Resources');

function writeJSON(relPath, data) {
  const fullPath = path.join(basePath, relPath);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, JSON.stringify(data, null, 2), 'utf8');
}

// ==========================================
// 1. Scopes (Declaración de Entidades)
// ==========================================
const entities = [
  'Itinerario',
  'ItineraryItem',
  'BudgetLine',
  'Supplier',
  'PackageTemplate',
  'Passenger',
  'PaymentSchedule'
];

entities.forEach(ent => {
  writeJSON(`metadata/scopes/${ent}.json`, {
    entity: true,
    object: true,
    type: ent === 'Supplier' ? 'BasePlus' : 'Base',
    customizable: true,
    importable: true,
    exportable: true
  });
});

// ==========================================
// 2. EntityDefs (Campos, Restricciones y Links)
// ==========================================

// --- Itinerario ---
writeJSON('metadata/entityDefs/Itinerario.json', {
  fields: {
    name: { type: 'varchar', required: true, maxLength: 150, pattern: '$noBadCharacters' },
    status: {
      type: 'enum',
      required: true,
      default: 'Cotización',
      options: ['Cotización', 'Confirmado', 'En Viaje', 'Finalizado', 'Cancelado'],
      audited: true
    },
    destination: { type: 'varchar', required: true, maxLength: 100, pattern: '$noBadCharacters' },
    startDate: { type: 'date', required: true },
    endDate: { type: 'date', required: true },
    quoteValidUntil: { type: 'datetime' }, // Expiración de tarifas
    whatsappStatus: {
      type: 'enum',
      default: 'NotSent',
      options: ['NotSent', 'Queued', 'Sent', 'Delivered', 'Read', 'Failed']
    },
    totalCost: { type: 'currency' },
    totalSelling: { type: 'currency' },
    grossProfit: { type: 'currency' },
    notes: { type: 'text' }
  },
  links: {
    opportunity: { type: 'belongsTo', entity: 'Opportunity', foreign: 'cItinerarios' },
    items: { type: 'hasMany', entity: 'ItineraryItem', foreign: 'itinerario' },
    budgetLines: { type: 'hasMany', entity: 'BudgetLine', foreign: 'itinerario' },
    passengers: { type: 'hasMany', entity: 'Passenger', foreign: 'itinerario' },
    paymentSchedules: { type: 'hasMany', entity: 'PaymentSchedule', foreign: 'itinerario' }
  }
});

// --- ItineraryItem (Detalle de Servicios) ---
writeJSON('metadata/entityDefs/ItineraryItem.json', {
  fields: {
    name: { type: 'varchar', required: true, maxLength: 150, pattern: '$noBadCharacters' },
    serviceType: {
      type: 'enum',
      required: true,
      default: 'Vuelo',
      options: ['Vuelo', 'Hotel', 'Traslado', 'Tour', 'Seguro', 'Crucero', 'Otro'],
      audited: true
    },
    serviceDate: { type: 'datetime', required: true },
    confirmationCode: { type: 'varchar', maxLength: 60, pattern: '$noBadCharacters' },
    status: {
      type: 'enum',
      default: 'Solicitado',
      options: ['Solicitado', 'Confirmado', 'Emitido', 'Cancelado']
    },
    notes: { type: 'text' }
  },
  links: {
    itinerario: { type: 'belongsTo', entity: 'Itinerario', foreign: 'items' },
    supplier: { type: 'belongsTo', entity: 'Supplier', foreign: 'itineraryItems' }
  }
});

// --- BudgetLine (Finanzas y Márgenes) ---
writeJSON('metadata/entityDefs/BudgetLine.json', {
  fields: {
    name: { type: 'varchar', required: true, maxLength: 150, pattern: '$noBadCharacters' },
    costPrice: { type: 'currency', required: true },
    marginRate: { type: 'float', required: true, default: 15 },
    sellingPrice: { type: 'currency', required: true },
    grossProfit: { type: 'currency', readOnly: true },
    notes: { type: 'text' }
  },
  links: {
    itinerario: { type: 'belongsTo', entity: 'Itinerario', foreign: 'budgetLines' },
    supplier: { type: 'belongsTo', entity: 'Supplier', foreign: 'budgetLines' }
  }
});

// --- Passenger (Pasajeros / Pasaportes) ---
writeJSON('metadata/entityDefs/Passenger.json', {
  fields: {
    name: { type: 'varchar', required: true, maxLength: 150, pattern: '$noBadCharacters' },
    documentType: {
      type: 'enum',
      required: true,
      default: 'Passport',
      options: ['Passport', 'DNI', 'IdentityCard', 'Other']
    },
    documentNumber: { type: 'varchar', required: true, maxLength: 50, pattern: '$noBadCharacters' },
    documentExpiration: { type: 'date' },
    birthDate: { type: 'date' },
    nationality: { type: 'varchar', maxLength: 80 },
    dietaryRestrictions: { type: 'varchar', maxLength: 150 },
    notes: { type: 'text' }
  },
  links: {
    itinerario: { type: 'belongsTo', entity: 'Itinerario', foreign: 'passengers' },
    contact: { type: 'belongsTo', entity: 'Contact', foreign: 'passengers' }
  }
});

// --- PaymentSchedule (Cronograma de Cuotas/Cobros) ---
writeJSON('metadata/entityDefs/PaymentSchedule.json', {
  fields: {
    name: { type: 'varchar', required: true, maxLength: 100 },
    dueDate: { type: 'date', required: true },
    amount: { type: 'currency', required: true },
    status: {
      type: 'enum',
      required: true,
      default: 'Pendiente',
      options: ['Pendiente', 'Pagado', 'Vencido', 'Cancelado'],
      audited: true
    },
    paymentMethod: {
      type: 'enum',
      options: ['Transferencia', 'Tarjeta', 'Efectivo', 'LinkDePago', 'Otro']
    },
    transactionId: { type: 'varchar', maxLength: 100 }
  },
  links: {
    itinerario: { type: 'belongsTo', entity: 'Itinerario', foreign: 'paymentSchedules' }
  }
});

// --- Supplier (Proveedores / Operadores) ---
writeJSON('metadata/entityDefs/Supplier.json', {
  fields: {
    name: { type: 'varchar', required: true, maxLength: 150, pattern: '$noBadCharacters' },
    supplierType: {
      type: 'enum',
      required: true,
      default: 'Operador Local',
      options: ['Hotel', 'Operador Local', 'Transporte', 'Guía', 'Aerolínea', 'Seguros', 'Mayorista']
    },
    contactEmail: { type: 'varchar', maxLength: 100, pattern: '$email' },
    contactPhone: { type: 'varchar', maxLength: 30, pattern: '$noBadCharacters' },
    paymentTerms: { type: 'text' }
  },
  links: {
    itineraryItems: { type: 'hasMany', entity: 'ItineraryItem', foreign: 'supplier' },
    budgetLines: { type: 'hasMany', entity: 'BudgetLine', foreign: 'supplier' }
  }
});

// --- PackageTemplate (Plantillas de Paquetes) ---
writeJSON('metadata/entityDefs/PackageTemplate.json', {
  fields: {
    name: { type: 'varchar', required: true, maxLength: 150, pattern: '$noBadCharacters' },
    destination: { type: 'varchar', required: true, maxLength: 100, pattern: '$noBadCharacters' },
    durationDays: { type: 'int', required: true, default: 1 },
    basePrice: { type: 'currency' },
    description: { type: 'text' }
  }
});

// --- Extensiones en Entidades Nativas ---
writeJSON('metadata/entityDefs/Opportunity.json', {
  fields: {
    whatsappChatId: { type: 'varchar', maxLength: 100 },
    lastActivepiecesSync: { type: 'datetime' }
  },
  links: {
    cItinerarios: { type: 'hasMany', entity: 'Itinerario', foreign: 'opportunity' }
  }
});

writeJSON('metadata/entityDefs/Contact.json', {
  links: {
    passengers: { type: 'hasMany', entity: 'Passenger', foreign: 'contact' }
  }
});

// ==========================================
// 3. Fórmulas de Automatización
// ==========================================
writeJSON('metadata/app/formula.json', {
  BudgetLine: {
    beforeSaveCustomScript: "ifThen(costPrice != null && marginRate != null, sellingPrice = costPrice * (1 + (marginRate / 100))); ifThen(sellingPrice != null && costPrice != null, grossProfit = sellingPrice - costPrice);"
  }
});

// ==========================================
// 4. Layouts (Vistas detalladas, listas y paneles)
// ==========================================

// --- Itinerario ---
writeJSON('layouts/Itinerario/detail.json', [
  {
    rows: [
      [{ name: 'name' }, { name: 'status' }],
      [{ name: 'destination' }, { name: 'opportunity' }],
      [{ name: 'startDate' }, { name: 'endDate' }],
      [{ name: 'quoteValidUntil' }, { name: 'whatsappStatus' }]
    ],
    style: 'default',
    label: 'Información General'
  },
  {
    rows: [
      [{ name: 'totalCost' }, { name: 'totalSelling' }],
      [{ name: 'grossProfit' }, false],
      [{ name: 'notes' }, false]
    ],
    style: 'default',
    label: 'Resumen Financiero y Notas'
  }
]);

writeJSON('layouts/Itinerario/list.json', [
  { name: 'name', link: true },
  { name: 'destination' },
  { name: 'status' },
  { name: 'startDate' },
  { name: 'endDate' },
  { name: 'totalSelling' },
  { name: 'grossProfit' }
]);

writeJSON('layouts/Itinerario/bottomPanels.json', [
  { name: 'passengers', label: 'Pasajeros' },
  { name: 'items', label: 'Servicios Turísticos' },
  { name: 'budgetLines', label: 'Finanzas y Márgenes' },
  { name: 'paymentSchedules', label: 'Cronograma de Pagos' }
]);

// --- BudgetLine ---
writeJSON('layouts/BudgetLine/detail.json', [
  {
    rows: [
      [{ name: 'name' }, { name: 'itinerario' }],
      [{ name: 'supplier' }, { name: 'costPrice' }],
      [{ name: 'marginRate' }, { name: 'sellingPrice' }],
      [{ name: 'grossProfit' }, false],
      [{ name: 'notes' }, false]
    ],
    style: 'default',
    label: 'Cálculo de Margen'
  }
]);

writeJSON('layouts/BudgetLine/list.json', [
  { name: 'name', link: true },
  { name: 'supplier' },
  { name: 'costPrice' },
  { name: 'marginRate' },
  { name: 'sellingPrice' },
  { name: 'grossProfit' }
]);

writeJSON('layouts/BudgetLine/listSmall.json', [
  { name: 'name', link: true },
  { name: 'costPrice' },
  { name: 'marginRate' },
  { name: 'sellingPrice' },
  { name: 'grossProfit' }
]);

// --- Passenger ---
writeJSON('layouts/Passenger/detail.json', [
  {
    rows: [
      [{ name: 'name' }, { name: 'itinerario' }],
      [{ name: 'contact' }, { name: 'birthDate' }],
      [{ name: 'documentType' }, { name: 'documentNumber' }],
      [{ name: 'documentExpiration' }, { name: 'nationality' }],
      [{ name: 'dietaryRestrictions' }, false],
      [{ name: 'notes' }, false]
    ],
    style: 'default',
    label: 'Datos del Pasajero'
  }
]);

writeJSON('layouts/Passenger/list.json', [
  { name: 'name', link: true },
  { name: 'documentType' },
  { name: 'documentNumber' },
  { name: 'documentExpiration' },
  { name: 'nationality' }
]);

writeJSON('layouts/Passenger/listSmall.json', [
  { name: 'name', link: true },
  { name: 'documentType' },
  { name: 'documentNumber' },
  { name: 'nationality' }
]);

// --- PaymentSchedule ---
writeJSON('layouts/PaymentSchedule/detail.json', [
  {
    rows: [
      [{ name: 'name' }, { name: 'itinerario' }],
      [{ name: 'dueDate' }, { name: 'amount' }],
      [{ name: 'status' }, { name: 'paymentMethod' }],
      [{ name: 'transactionId' }, false]
    ],
    style: 'default',
    label: 'Programación de Cobro'
  }
]);

writeJSON('layouts/PaymentSchedule/list.json', [
  { name: 'name', link: true },
  { name: 'dueDate' },
  { name: 'amount' },
  { name: 'status' },
  { name: 'paymentMethod' }
]);

writeJSON('layouts/PaymentSchedule/listSmall.json', [
  { name: 'name', link: true },
  { name: 'dueDate' },
  { name: 'amount' },
  { name: 'status' }
]);

// --- ItineraryItem ---
writeJSON('layouts/ItineraryItem/listSmall.json', [
  { name: 'name', link: true },
  { name: 'serviceType' },
  { name: 'serviceDate' },
  { name: 'status' },
  { name: 'confirmationCode' }
]);

writeJSON('layouts/Opportunity/bottomPanels.json', [
  { name: 'cItinerarios' }
]);

writeJSON('metadata/app/tabList.json', [
  'Itinerario',
  'Passenger',
  'PaymentSchedule',
  'Supplier',
  'PackageTemplate',
  'Opportunity',
  'Contact',
  'Account'
]);

console.log('✅ Esquema CRM de Viajes 2026 configurado exitosamente en custom/.');