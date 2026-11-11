import type { LogoItem } from '@/components/ui/LogoLoop.vue';

export const hardwarePartners: LogoItem[] = [
  { name: 'DeWalt', alt: 'DeWalt Power Tools', src: '/images/brands/hardware/dewalt.png' },
  { name: 'Makita', alt: 'Makita Power Tools', src: '/images/brands/hardware/makita.png' },
  { name: 'Powerhouse', alt: 'Powerhouse Built Tough Tools', src: '/images/brands/hardware/powerhouse.png' },
  { name: 'Stanley', alt: 'Stanley Tools & Hardware', src: '/images/brands/hardware/stanley.png' },
  { name: 'STIHL', alt: 'STIHL Industrial Tools', src: '/images/brands/hardware/stihl.png' },
  { name: 'Yale', alt: 'Yale Security & Hardware', src: '/images/brands/hardware/yale.png' },
  { name: 'Zekoki', alt: 'Zekoki Tools', src: '/images/brands/hardware/zekoki.png' },
  { name: 'Jr. Kawasaki', alt: 'Jr. Kawasaki Professional Tools', src: '/images/brands/hardware/jr-kawasaki.png' },
  { name: 'Lotus', alt: 'Lotus Performance Delivered', src: '/images/brands/hardware/lotus.png' },
  { name: 'Yamato', alt: 'Yamato Hardware', src: '/images/brands/hardware/yamato.png' },
  { name: 'Butterfly', alt: 'Butterfly Tools Taiwan', src: '/images/brands/hardware/butterfly.png' },
  { name: 'Tacoma', alt: 'Tacoma Hardware', src: '/images/brands/hardware/tacoma.png' },
  { name: 'Hoyoma', alt: 'Hoyoma Japan Tools', src: '/images/brands/hardware/hoyoma.png' },
  { name: 'DCA', alt: 'DCA Professional Power Tools', src: '/images/brands/hardware/dca.png' },
  { name: 'Ingco', alt: 'Ingco Make the World in Your Hands', src: '/images/brands/hardware/ingco.png' },
];

export const electricalPartners: LogoItem[] = [
  { name: 'Himel', alt: 'Himel Electrical Supplies', src: '/images/brands/electrical/himel.png' },
  { name: 'ROYU', alt: 'ROYU Wires & Devices', src: '/images/brands/electrical/royu.png' },
  { name: 'Panasonic', alt: 'Panasonic Electrical', src: '/images/brands/electrical/panasonic.png' },
  { name: 'Phelps Dodge', alt: 'Phelps Dodge Cables & Wires', src: '/images/brands/electrical/phelps-dodge.png' },
  { name: 'General Electric', alt: 'General Electric (GE)', src: '/images/brands/electrical/general-electric.png' },
  { name: 'Koten', alt: 'Koten Electrical Products', src: '/images/brands/electrical/koten.png' },
  { name: 'Schneider Electric', alt: 'Schneider Electric', src: '/images/brands/electrical/schneider-electric.png' },
  { name: 'Philflex', alt: 'Philflex Wires & Cables', src: '/images/brands/electrical/philflex.png' },
  { name: 'Duraflex', alt: 'Duraflex American Wire & Cable', src: '/images/brands/electrical/duraflex.png' },
  { name: 'Hypertech', alt: 'Hypertech Wire and Cable', src: '/images/brands/electrical/hypertech.png' },
  { name: 'Pureflex', alt: 'Pureflex Cables', src: '/images/brands/electrical/pureflex.png' },
  { name: 'Paciflex', alt: 'Paciflex Electrical Wires', src: '/images/brands/electrical/paciflex.png' },
];

export const pipePartners: LogoItem[] = [
  { name: 'Crown Pipes', alt: 'Crown Pipes World Class Pipes and Fittings', src: '/images/brands/pipes/crown-pipes.png' },
  { name: 'Emerald', alt: 'Emerald PVC Pipes & Fittings', src: '/images/brands/pipes/emerald.png' },
  { name: 'Neltex', alt: 'Neltex Piping Systems', src: '/images/brands/pipes/neltex.png' },
  { name: 'Nichi', alt: 'Nichi RSC Conduits', src: '/images/brands/pipes/nichi.png' },
  { name: 'McGill', alt: 'McGill Pipes & Fittings', src: '/images/brands/pipes/mcgill.png' },
  { name: 'Dupro', alt: 'Dupro Electrical Conduits', src: '/images/brands/pipes/dupro.png' },
  { name: 'Nikon', alt: 'Nikon EMT Conduits', src: '/images/brands/pipes/nikon.png' },
  { name: 'Panasonic', alt: 'Panasonic Conduits', src: '/images/brands/pipes/panasonic.png' },
  { name: 'Atlanta Smartube', alt: 'Atlanta Smartube Conduits', src: '/images/brands/pipes/smartube.png' },
];

export const safetyPartners: LogoItem[] = [
  { name: '3M', alt: '3M Safety and Protective Equipment', src: '/images/brands/safety/3m.svg' },
  { name: 'Honeywell', alt: 'Honeywell Industrial PPE & Safety', src: '/images/brands/safety/honeywell.svg' },
  { name: 'Ansell', alt: 'Ansell Electrical Insulating Gloves', src: '/images/brands/safety/ansell.svg' },
  { name: 'MSA Safety', alt: 'MSA Safety Equipment', src: '/images/brands/safety/msa.svg' },
  { name: 'Delta Plus', alt: 'Delta Plus Industrial Head & Body Protection', src: '/images/brands/safety/deltaplus.svg' },
  { name: 'CATU Safety', alt: 'CATU Electrical Safety Equipment', src: '/images/brands/safety/catu.svg' },
  { name: 'Kidde', alt: 'Kidde Fire Protection & Safety', src: '/images/brands/safety/kidde.svg' },
];

export const brandPartnersByCategory: Record<string, { logos: LogoItem[]; direction: 'left' | 'right'; ariaLabel: string }> = {
  A: {
    logos: hardwarePartners,
    direction: 'left',
    ariaLabel: 'Hardware & machinery brand partners',
  },
  'hardware-machinery': {
    logos: hardwarePartners,
    direction: 'left',
    ariaLabel: 'Hardware & machinery brand partners',
  },
  B: {
    logos: electricalPartners,
    direction: 'right',
    ariaLabel: 'Electrical materials brand partners',
  },
  'electrical-materials': {
    logos: electricalPartners,
    direction: 'right',
    ariaLabel: 'Electrical materials brand partners',
  },
  C: {
    logos: pipePartners,
    direction: 'left',
    ariaLabel: 'Pipes & conduits brand partners',
  },
  'pipes-conduits': {
    logos: pipePartners,
    direction: 'left',
    ariaLabel: 'Pipes & conduits brand partners',
  },
  D: {
    logos: safetyPartners,
    direction: 'right',
    ariaLabel: 'Safety products brand partners',
  },
  'safety-products': {
    logos: safetyPartners,
    direction: 'right',
    ariaLabel: 'Safety products brand partners',
  },
};
