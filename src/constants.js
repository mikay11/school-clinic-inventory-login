export const CATEGORIES = ['Tablet', 'Capsule', 'Syrup', 'Liquid', 'Ointment', 'Other']

export const STOCK_LOCATIONS = ['Cabinet A', 'Cabinet B', 'Cabinet C', 'Refrigerator', 'Emergency Kit']

export const MEDICINE_NAMES = [
  'Biogesic', 'Paracetamol', 'Medicol', 'Tempra', 'Calpol', 'Mefenamic Acid',
  'Ibuprofen', 'Aspirin', 'Alaxan', 'Amoxicillin', 'Co-Amoxiclav', 'Doxycycline',
  'Cetirizine', 'Loratadine', 'Chlorphenamine', 'Lagundi', 'Solmux', 'Neozep',
  'Bioflu', 'Kremil-S', 'Diatabs', 'Hydrite', 'Oral Rehydration Salts', 'Bonamine',
  'Buscopan', 'Ambil', 'Multivitamins', 'Ascorbic Acid', 'Ferrous Sulfate',
  'Salbutamol', 'Betadine', 'Povidone-Iodine', 'Hydrogen Peroxide',
  'Efficascent Oil', 'Katinko',
]

export const GENERIC_NAMES = [
  'Paracetamol', 'Mefenamic Acid', 'Ibuprofen', 'Aspirin', 'Amoxicillin',
  'Co-Amoxiclav', 'Doxycycline', 'Cetirizine', 'Loratadine', 'Chlorphenamine',
  'Lagundi', 'Carbocisteine', 'Salbutamol', 'Ascorbic Acid', 'Ferrous Sulfate',
  'Multivitamins', 'Oral Rehydration Salts', 'Povidone-Iodine',
  'Hydrogen Peroxide', 'Simethicone', 'Hyoscine Butylbromide', 'Meclizine',
  'Dimenhydrinate', 'Menthol', 'Methyl Salicylate', 'Aluminum Hydroxide',
  'Magnesium Hydroxide',
]

export const STOCK_OUT_REASONS = {
  dispensed: 'Dispensed to student or staff',
  expired: 'Expired stock disposal',
  damaged: 'Damaged or contaminated',
  adjustment: 'Inventory adjustment',
  other: 'Other',
}

export const NAV_ITEMS = [
  { to: '/dashboard', label: 'Dashboard', icon: 'dashboard' },
  { to: '/inventory', label: 'Medicine Inventory', icon: 'medicine' },
  { to: '/stock-in', label: 'Stock In', icon: 'stock-in' },
  { to: '/stock-out', label: 'Stock Out', icon: 'stock-out' },
  { to: '/expiration', label: 'Expiration Monitoring', icon: 'clock' },
  { to: '/notifications', label: 'Notifications', icon: 'bell' },
  { to: '/reports', label: 'Reports', icon: 'reports' },
  { to: '/settings', label: 'Settings', icon: 'settings' },
]

export function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

export function formatNumber(value) {
  return Number(value || 0).toLocaleString('en-US')
}
