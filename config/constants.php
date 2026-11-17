<?php
declare(strict_types=1);

const MEDICINE_NAMES = [
    'Biogesic', 'Paracetamol', 'Medicol', 'Tempra', 'Calpol', 'Mefenamic Acid',
    'Ibuprofen', 'Aspirin', 'Alaxan', 'Amoxicillin', 'Co-Amoxiclav', 'Doxycycline',
    'Cetirizine', 'Loratadine', 'Chlorphenamine', 'Lagundi', 'Solmux', 'Neozep',
    'Bioflu', 'Kremil-S', 'Diatabs', 'Hydrite', 'Oral Rehydration Salts', 'Bonamine',
    'Buscopan', 'Ambil', 'Multivitamins', 'Ascorbic Acid', 'Ferrous Sulfate',
    'Salbutamol', 'Betadine', 'Povidone-Iodine', 'Hydrogen Peroxide',
    'Efficascent Oil', 'Katinko',
];

const GENERIC_NAMES = [
    'Paracetamol', 'Mefenamic Acid', 'Ibuprofen', 'Aspirin', 'Amoxicillin',
    'Co-Amoxiclav', 'Doxycycline', 'Cetirizine', 'Loratadine', 'Chlorphenamine',
    'Lagundi', 'Carbocisteine', 'Salbutamol', 'Ascorbic Acid', 'Ferrous Sulfate',
    'Multivitamins', 'Oral Rehydration Salts', 'Povidone-Iodine',
    'Hydrogen Peroxide', 'Simethicone', 'Hyoscine Butylbromide', 'Meclizine',
    'Dimenhydrinate', 'Menthol', 'Methyl Salicylate', 'Aluminum Hydroxide',
    'Magnesium Hydroxide',
];

const CATEGORIES = ['Tablet', 'Capsule', 'Syrup', 'Liquid', 'Ointment', 'Other'];

const STOCK_LOCATIONS = [
    'Cabinet A', 'Cabinet B', 'Cabinet C', 'Refrigerator', 'Emergency Kit',
];

const STOCK_OUT_REASONS = [
    'dispensed' => 'Dispensed to student or staff',
    'expired' => 'Expired stock disposal',
    'damaged' => 'Damaged or contaminated',
    'adjustment' => 'Inventory adjustment',
    'other' => 'Other',
];
