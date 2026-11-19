<?php

declare(strict_types=1);

namespace App\Clasificacion;

/**
 * Clasificación temática ASJC (All Science Journal Classification) de Scopus: 334 categorías con código de cuatro
 * dígitos, agrupadas en 27 campos (los dos primeros dígitos; la categoría «XX00» es la general del campo) y en cinco
 * grandes áreas. Fuente: lista oficial de Elsevier
 * (https://www.elsevier.support/scopus/answer/what-is-the-complete-list-of-asjc-subject-areas-in-scopus); los
 * nombres en español son una traducción propia.
 *
 * Las materias de la plataforma se asocian a una o más categorías (Materia::getAsjc()); las guías y los recursos
 * heredan así su campo y gran área para navegar y filtrar.
 */
final class Asjc
{
    /** Grandes áreas, en el orden en que se muestran. */
    public const array AREAS = ['salud', 'vida', 'fisicas', 'sociales', 'multidisciplinaria'];

    /** Gran área de cada campo (dos primeros dígitos del código). */
    private const array AREA_DE_CAMPO = [
        10 => 'multidisciplinaria',
        11 => 'vida', 13 => 'vida', 24 => 'vida', 28 => 'vida', 30 => 'vida',
        12 => 'sociales', 14 => 'sociales', 18 => 'sociales', 20 => 'sociales', 32 => 'sociales', 33 => 'sociales',
        15 => 'fisicas', 16 => 'fisicas', 17 => 'fisicas', 19 => 'fisicas', 21 => 'fisicas', 22 => 'fisicas',
        23 => 'fisicas', 25 => 'fisicas', 26 => 'fisicas', 31 => 'fisicas',
        27 => 'salud', 29 => 'salud', 34 => 'salud', 35 => 'salud', 36 => 'salud',
    ];

    /** Código => [nombre en español, nombre en inglés]. */
    public const array CATEGORIAS = [
        1000 => ['Multidisciplinaria', 'Multidisciplinary'],
        1100 => ['Ciencias agrícolas y biológicas (general)', 'General Agricultural and Biological Sciences'],
        1101 => ['Ciencias agrícolas y biológicas (varios)', 'Agricultural and Biological Sciences (miscellaneous)'],
        1102 => ['Agronomía y ciencia de cultivos', 'Agronomy and Crop Science'],
        1103 => ['Ciencia animal y zoología', 'Animal Science and Zoology'],
        1104 => ['Ciencias acuáticas', 'Aquatic Science'],
        1105 => ['Ecología, evolución, comportamiento y sistemática', 'Ecology, Evolution, Behavior and Systematics'],
        1106 => ['Ciencia de los alimentos', 'Food Science'],
        1107 => ['Ciencias forestales', 'Forestry'],
        1108 => ['Horticultura', 'Horticulture'],
        1109 => ['Entomología', 'Insect Science'],
        1110 => ['Ciencias de las plantas', 'Plant Science'],
        1111 => ['Ciencia del suelo', 'Soil Science'],
        1200 => ['Artes y humanidades (general)', 'General Arts and Humanities'],
        1201 => ['Artes y humanidades (varios)', 'Arts and Humanities (miscellaneous)'],
        1202 => ['Historia', 'History'],
        1203 => ['Lengua y lingüística', 'Language and Linguistics'],
        1204 => ['Arqueología (artes y humanidades)', 'Archeology (arts and humanities)'],
        1205 => ['Estudios clásicos', 'Classics'],
        1206 => ['Conservación', 'Conservation'],
        1207 => ['Historia y filosofía de la ciencia', 'History and Philosophy of Science'],
        1208 => ['Literatura y teoría literaria', 'Literature and Literary Theory'],
        1209 => ['Museología', 'Museology'],
        1210 => ['Música', 'Music'],
        1211 => ['Filosofía', 'Philosophy'],
        1212 => ['Estudios religiosos', 'Religious Studies'],
        1213 => ['Artes visuales y escénicas', 'Visual Arts and Performing Arts'],
        1300 => ['Bioquímica, genética y biología molecular (general)', 'General Biochemistry, Genetics and Molecular Biology'],
        1301 => ['Bioquímica, genética y biología molecular (varios)', 'Biochemistry, Genetics and Molecular Biology (miscellaneous)'],
        1302 => ['Envejecimiento', 'Aging'],
        1303 => ['Bioquímica', 'Biochemistry'],
        1304 => ['Biofísica', 'Biophysics'],
        1305 => ['Biotecnología', 'Biotechnology'],
        1306 => ['Investigación del cáncer', 'Cancer Research'],
        1307 => ['Biología celular', 'Cell Biology'],
        1308 => ['Bioquímica clínica', 'Clinical Biochemistry'],
        1309 => ['Biología del desarrollo', 'Developmental Biology'],
        1310 => ['Endocrinología', 'Endocrinology'],
        1311 => ['Genética', 'Genetics'],
        1312 => ['Biología molecular', 'Molecular Biology'],
        1313 => ['Medicina molecular', 'Molecular Medicine'],
        1314 => ['Fisiología', 'Physiology'],
        1315 => ['Biología estructural', 'Structural Biology'],
        1400 => ['Negocios, administración y contabilidad (general)', 'General Business, Management and Accounting'],
        1401 => ['Negocios, administración y contabilidad (varios)', 'Business, Management and Accounting (miscellaneous)'],
        1402 => ['Contabilidad', 'Accounting'],
        1403 => ['Negocios y gestión internacional', 'Business and International Management'],
        1404 => ['Sistemas de información para la gestión', 'Management Information Systems'],
        1405 => ['Gestión de la tecnología y la innovación', 'Management of Technology and Innovation'],
        1406 => ['Marketing', 'Marketing'],
        1407 => ['Comportamiento organizacional y gestión de recursos humanos', 'Organizational Behavior and Human Resource Management'],
        1408 => ['Estrategia y gestión', 'Strategy and Management'],
        1409 => ['Gestión del turismo, el ocio y la hotelería', 'Tourism, Leisure and Hospitality Management'],
        1410 => ['Relaciones laborales', 'Industrial Relations'],
        1500 => ['Ingeniería química (general)', 'General Chemical Engineering'],
        1501 => ['Ingeniería química (varios)', 'Chemical Engineering (miscellaneous)'],
        1502 => ['Bioingeniería', 'Bioengineering'],
        1503 => ['Catálisis', 'Catalysis'],
        1504 => ['Salud y seguridad química', 'Chemical Health and Safety'],
        1505 => ['Química de coloides y superficies', 'Colloid and Surface Chemistry'],
        1506 => ['Filtración y separación', 'Filtration and Separation'],
        1507 => ['Flujo de fluidos y procesos de transferencia', 'Fluid Flow and Transfer Processes'],
        1508 => ['Química y tecnología de procesos', 'Process Chemistry and Technology'],
        1600 => ['Química (general)', 'General Chemistry'],
        1601 => ['Química (varios)', 'Chemistry (miscellaneous)'],
        1602 => ['Química analítica', 'Analytical Chemistry'],
        1603 => ['Electroquímica', 'Electrochemistry'],
        1604 => ['Química inorgánica', 'Inorganic Chemistry'],
        1605 => ['Química orgánica', 'Organic Chemistry'],
        1606 => ['Química física y teórica', 'Physical and Theoretical Chemistry'],
        1607 => ['Espectroscopía', 'Spectroscopy'],
        1700 => ['Ciencias de la computación (general)', 'General Computer Science'],
        1701 => ['Ciencias de la computación (varios)', 'Computer Science (miscellaneous)'],
        1702 => ['Inteligencia artificial', 'Artificial Intelligence'],
        1703 => ['Teoría computacional y matemáticas', 'Computational Theory and Mathematics'],
        1704 => ['Gráficos por computador y diseño asistido', 'Computer Graphics and Computer-Aided Design'],
        1705 => ['Redes y comunicaciones de computadores', 'Computer Networks and Communications'],
        1706 => ['Aplicaciones de las ciencias de la computación', 'Computer Science Applications'],
        1707 => ['Visión por computador y reconocimiento de patrones', 'Computer Vision and Pattern Recognition'],
        1708 => ['Hardware y arquitectura', 'Hardware and Architecture'],
        1709 => ['Interacción persona-computador', 'Human-Computer Interaction'],
        1710 => ['Sistemas de información', 'Information Systems'],
        1711 => ['Procesamiento de señales', 'Signal Processing'],
        1712 => ['Software', 'Software'],
        1800 => ['Ciencias de la decisión (general)', 'General Decision Sciences'],
        1801 => ['Ciencias de la decisión (varios)', 'Decision Sciences (miscellaneous)'],
        1802 => ['Sistemas de información y gestión', 'Information Systems and Management'],
        1803 => ['Ciencias de la gestión e investigación de operaciones', 'Management Science and Operations Research'],
        1804 => ['Estadística, probabilidad e incertidumbre', 'Statistics, Probability and Uncertainty'],
        1900 => ['Ciencias de la Tierra y planetarias (general)', 'General Earth and Planetary Sciences'],
        1901 => ['Ciencias de la Tierra y planetarias (varios)', 'Earth and Planetary Sciences (miscellaneous)'],
        1902 => ['Ciencias atmosféricas', 'Atmospheric Science'],
        1903 => ['Computación en ciencias de la Tierra', 'Computers in Earth Sciences'],
        1904 => ['Procesos de la superficie terrestre', 'Earth-Surface Processes'],
        1905 => ['Geología económica', 'Economic Geology'],
        1906 => ['Geoquímica y petrología', 'Geochemistry and Petrology'],
        1907 => ['Geología', 'Geology'],
        1908 => ['Geofísica', 'Geophysics'],
        1909 => ['Ingeniería geotécnica y geología aplicada', 'Geotechnical Engineering and Engineering Geology'],
        1910 => ['Oceanografía', 'Oceanography'],
        1911 => ['Paleontología', 'Paleontology'],
        1912 => ['Ciencias espaciales y planetarias', 'Space and Planetary Science'],
        1913 => ['Estratigrafía', 'Stratigraphy'],
        2000 => ['Economía, econometría y finanzas (general)', 'General Economics, Econometrics and Finance'],
        2001 => ['Economía, econometría y finanzas (varios)', 'Economics, Econometrics and Finance (miscellaneous)'],
        2002 => ['Economía y econometría', 'Economics and Econometrics'],
        2003 => ['Finanzas', 'Finance'],
        2100 => ['Energía (general)', 'General Energy'],
        2101 => ['Energía (varios)', 'Energy (miscellaneous)'],
        2102 => ['Ingeniería energética y tecnología eléctrica', 'Energy Engineering and Power Technology'],
        2103 => ['Tecnología de combustibles', 'Fuel Technology'],
        2104 => ['Energía e ingeniería nuclear', 'Nuclear Energy and Engineering'],
        2105 => ['Energías renovables, sostenibilidad y medio ambiente', 'Renewable Energy, Sustainability and the Environment'],
        2200 => ['Ingeniería (general)', 'General Engineering'],
        2201 => ['Ingeniería (varios)', 'Engineering (miscellaneous)'],
        2202 => ['Ingeniería aeroespacial', 'Aerospace Engineering'],
        2203 => ['Ingeniería automotriz', 'Automotive Engineering'],
        2204 => ['Ingeniería biomédica', 'Biomedical Engineering'],
        2205 => ['Ingeniería civil y estructural', 'Civil and Structural Engineering'],
        2206 => ['Mecánica computacional', 'Computational Mechanics'],
        2207 => ['Ingeniería de control y sistemas', 'Control and Systems Engineering'],
        2208 => ['Ingeniería eléctrica y electrónica', 'Electrical and Electronic Engineering'],
        2209 => ['Ingeniería industrial y de manufactura', 'Industrial and Manufacturing Engineering'],
        2210 => ['Ingeniería mecánica', 'Mechanical Engineering'],
        2211 => ['Mecánica de materiales', 'Mechanics of Materials'],
        2212 => ['Ingeniería oceánica', 'Ocean Engineering'],
        2213 => ['Seguridad, riesgo, confiabilidad y calidad', 'Safety, Risk, Reliability and Quality'],
        2214 => ['Tecnología de medios', 'Media Technology'],
        2215 => ['Edificación y construcción', 'Building and Construction'],
        2216 => ['Arquitectura', 'Architecture'],
        2300 => ['Ciencias ambientales (general)', 'General Environmental Science'],
        2301 => ['Ciencias ambientales (varios)', 'Environmental Science (miscellaneous)'],
        2302 => ['Modelación ecológica', 'Ecological Modeling'],
        2303 => ['Ecología', 'Ecology'],
        2304 => ['Química ambiental', 'Environmental Chemistry'],
        2305 => ['Ingeniería ambiental', 'Environmental Engineering'],
        2306 => ['Cambio global y planetario', 'Global and Planetary Change'],
        2307 => ['Salud, toxicología y mutagénesis', 'Health, Toxicology and Mutagenesis'],
        2308 => ['Gestión, monitoreo, políticas y derecho', 'Management, Monitoring, Policy and Law'],
        2309 => ['Conservación de la naturaleza y el paisaje', 'Nature and Landscape Conservation'],
        2310 => ['Contaminación', 'Pollution'],
        2311 => ['Gestión y disposición de residuos', 'Waste Management and Disposal'],
        2312 => ['Ciencia y tecnología del agua', 'Water Science and Technology'],
        2400 => ['Inmunología y microbiología (general)', 'General Immunology and Microbiology'],
        2401 => ['Inmunología y microbiología (varios)', 'Immunology and Microbiology (miscellaneous)'],
        2402 => ['Microbiología aplicada y biotecnología', 'Applied Microbiology and Biotechnology'],
        2403 => ['Inmunología', 'Immunology'],
        2404 => ['Microbiología', 'Microbiology'],
        2405 => ['Parasitología', 'Parasitology'],
        2406 => ['Virología', 'Virology'],
        2500 => ['Ciencia de materiales (general)', 'General Materials Science'],
        2501 => ['Ciencia de materiales (varios)', 'Materials Science (miscellaneous)'],
        2502 => ['Biomateriales', 'Biomaterials'],
        2503 => ['Cerámicas y compuestos', 'Ceramics and Composites'],
        2504 => ['Materiales electrónicos, ópticos y magnéticos', 'Electronic, Optical and Magnetic Materials'],
        2505 => ['Química de materiales', 'Materials Chemistry'],
        2506 => ['Metales y aleaciones', 'Metals and Alloys'],
        2507 => ['Polímeros y plásticos', 'Polymers and Plastics'],
        2508 => ['Superficies, recubrimientos y películas', 'Surfaces, Coatings and Films'],
        2600 => ['Matemáticas (general)', 'General Mathematics'],
        2601 => ['Matemáticas (varios)', 'Mathematics (miscellaneous)'],
        2602 => ['Álgebra y teoría de números', 'Algebra and Number Theory'],
        2603 => ['Análisis', 'Analysis'],
        2604 => ['Matemática aplicada', 'Applied Mathematics'],
        2605 => ['Matemática computacional', 'Computational Mathematics'],
        2606 => ['Control y optimización', 'Control and Optimization'],
        2607 => ['Matemática discreta y combinatoria', 'Discrete Mathematics and Combinatorics'],
        2608 => ['Geometría y topología', 'Geometry and Topology'],
        2609 => ['Lógica', 'Logic'],
        2610 => ['Física matemática', 'Mathematical Physics'],
        2611 => ['Modelación y simulación', 'Modeling and Simulation'],
        2612 => ['Análisis numérico', 'Numerical Analysis'],
        2613 => ['Estadística y probabilidad', 'Statistics and Probability'],
        2614 => ['Ciencias de la computación teórica', 'Theoretical Computer Science'],
        2700 => ['Medicina (general)', 'General Medicine'],
        2701 => ['Medicina (varios)', 'Medicine (miscellaneous)'],
        2702 => ['Anatomía', 'Anatomy'],
        2703 => ['Anestesiología y medicina del dolor', 'Anesthesiology and Pain Medicine'],
        2704 => ['Bioquímica (médica)', 'Biochemistry (medical)'],
        2705 => ['Cardiología y medicina cardiovascular', 'Cardiology and Cardiovascular Medicine'],
        2706 => ['Cuidados críticos y medicina intensiva', 'Critical Care and Intensive Care Medicine'],
        2707 => ['Medicina complementaria y alternativa', 'Complementary and Alternative Medicine'],
        2708 => ['Dermatología', 'Dermatology'],
        2709 => ['Guías de medicamentos', 'Drug Guides'],
        2710 => ['Embriología', 'Embryology'],
        2711 => ['Medicina de urgencia', 'Emergency Medicine'],
        2712 => ['Endocrinología, diabetes y metabolismo', 'Endocrinology, Diabetes and Metabolism'],
        2713 => ['Epidemiología', 'Epidemiology'],
        2714 => ['Medicina familiar', 'Family Practice'],
        2715 => ['Gastroenterología', 'Gastroenterology'],
        2716 => ['Genética (clínica)', 'Genetics (clinical)'],
        2717 => ['Geriatría y gerontología', 'Geriatrics and Gerontology'],
        2718 => ['Informática en salud', 'Health Informatics'],
        2719 => ['Políticas de salud', 'Health Policy'],
        2720 => ['Hematología', 'Hematology'],
        2721 => ['Hepatología', 'Hepatology'],
        2722 => ['Histología', 'Histology'],
        2723 => ['Inmunología y alergia', 'Immunology and Allergy'],
        2724 => ['Medicina interna', 'Internal Medicine'],
        2725 => ['Enfermedades infecciosas', 'Infectious Diseases'],
        2726 => ['Microbiología (médica)', 'Microbiology (medical)'],
        2727 => ['Nefrología', 'Nephrology'],
        2728 => ['Neurología (clínica)', 'Neurology (clinical)'],
        2729 => ['Obstetricia y ginecología', 'Obstetrics and Gynecology'],
        2730 => ['Oncología', 'Oncology'],
        2731 => ['Oftalmología', 'Ophthalmology'],
        2732 => ['Ortopedia y medicina deportiva', 'Orthopedics and Sports Medicine'],
        2733 => ['Otorrinolaringología', 'Otorhinolaryngology'],
        2734 => ['Patología y medicina forense', 'Pathology and Forensic Medicine'],
        2735 => ['Pediatría, perinatología y salud infantil', 'Pediatrics, Perinatology and Child Health'],
        2736 => ['Farmacología (médica)', 'Pharmacology (medical)'],
        2737 => ['Fisiología (médica)', 'Physiology (medical)'],
        2738 => ['Psiquiatría y salud mental', 'Psychiatry and Mental Health'],
        2739 => ['Salud pública, ambiental y ocupacional', 'Public Health, Environmental and Occupational Health'],
        2740 => ['Medicina pulmonar y respiratoria', 'Pulmonary and Respiratory Medicine'],
        2741 => ['Radiología, medicina nuclear e imagenología', 'Radiology, Nuclear Medicine and Imaging'],
        2742 => ['Rehabilitación', 'Rehabilitation'],
        2743 => ['Medicina reproductiva', 'Reproductive Medicine'],
        2744 => ['Revisiones y obras de referencia (médicas)', 'Reviews and References (medical)'],
        2745 => ['Reumatología', 'Rheumatology'],
        2746 => ['Cirugía', 'Surgery'],
        2747 => ['Trasplante', 'Transplantation'],
        2748 => ['Urología', 'Urology'],
        2800 => ['Neurociencia (general)', 'General Neuroscience'],
        2801 => ['Neurociencia (varios)', 'Neuroscience (miscellaneous)'],
        2802 => ['Neurociencia del comportamiento', 'Behavioral Neuroscience'],
        2803 => ['Psiquiatría biológica', 'Biological Psychiatry'],
        2804 => ['Neurociencia celular y molecular', 'Cellular and Molecular Neuroscience'],
        2805 => ['Neurociencia cognitiva', 'Cognitive Neuroscience'],
        2806 => ['Neurociencia del desarrollo', 'Developmental Neuroscience'],
        2807 => ['Sistemas endocrino y autónomo', 'Endocrine and Autonomic Systems'],
        2808 => ['Neurología', 'Neurology'],
        2809 => ['Sistemas sensoriales', 'Sensory Systems'],
        2900 => ['Enfermería (general)', 'General Nursing'],
        2901 => ['Enfermería (varios)', 'Nursing (miscellaneous)'],
        2902 => ['Enfermería avanzada y especializada', 'Advanced and Specialized Nursing'],
        2903 => ['Valoración y diagnóstico', 'Assessment and Diagnosis'],
        2904 => ['Planificación de cuidados', 'Care Planning'],
        2905 => ['Atención comunitaria y domiciliaria', 'Community and Home Care'],
        2906 => ['Enfermería en cuidados críticos', 'Critical Care Nursing'],
        2907 => ['Enfermería de urgencia', 'Emergency Nursing'],
        2908 => ['Fundamentos y habilidades', 'Fundamentals and Skills'],
        2909 => ['Gerontología', 'Gerontology'],
        2910 => ['Aspectos éticos, legales y profesionales', 'Issues, Ethics and Legal Aspects'],
        2911 => ['Liderazgo y gestión', 'Leadership and Management'],
        2912 => ['Técnico en enfermería (LPN y LVN)', 'LPN and LVN'],
        2913 => ['Maternidad y obstetricia', 'Maternity and Midwifery'],
        2914 => ['Enfermería médico-quirúrgica', 'Medical and Surgical Nursing'],
        2915 => ['Auxiliar de enfermería', 'Nurse Assisting'],
        2916 => ['Nutrición y dietética', 'Nutrition and Dietetics'],
        2917 => ['Oncología (enfermería)', 'Oncology (nursing)'],
        2918 => ['Fisiopatología', 'Pathophysiology'],
        2919 => ['Pediatría', 'Pediatrics'],
        2920 => ['Farmacología (enfermería)', 'Pharmacology (nursing)'],
        2921 => ['Salud mental y psiquiatría', 'Psychiatric Mental Health'],
        2922 => ['Investigación y teoría', 'Research and Theory'],
        2923 => ['Revisión y preparación de exámenes', 'Review and Exam Preparation'],
        3000 => ['Farmacología, toxicología y farmacia (general)', 'General Pharmacology, Toxicology and Pharmaceutics'],
        3001 => ['Farmacología, toxicología y farmacia (varios)', 'Pharmacology, Toxicology and Pharmaceutics (miscellaneous)'],
        3002 => ['Descubrimiento de fármacos', 'Drug Discovery'],
        3003 => ['Ciencias farmacéuticas', 'Pharmaceutical Science'],
        3004 => ['Farmacología', 'Pharmacology'],
        3005 => ['Toxicología', 'Toxicology'],
        3100 => ['Física y astronomía (general)', 'General Physics and Astronomy'],
        3101 => ['Física y astronomía (varios)', 'Physics and Astronomy (miscellaneous)'],
        3102 => ['Acústica y ultrasonido', 'Acoustics and Ultrasonics'],
        3103 => ['Astronomía y astrofísica', 'Astronomy and Astrophysics'],
        3104 => ['Física de la materia condensada', 'Condensed Matter Physics'],
        3105 => ['Instrumentación', 'Instrumentation'],
        3106 => ['Física nuclear y de altas energías', 'Nuclear and High Energy Physics'],
        3107 => ['Física atómica y molecular, y óptica', 'Atomic and Molecular Physics, and Optics'],
        3108 => ['Radiación', 'Radiation'],
        3109 => ['Física estadística y no lineal', 'Statistical and Nonlinear Physics'],
        3110 => ['Superficies e interfaces', 'Surfaces and Interfaces'],
        3200 => ['Psicología (general)', 'General Psychology'],
        3201 => ['Psicología (varios)', 'Psychology (miscellaneous)'],
        3202 => ['Psicología aplicada', 'Applied Psychology'],
        3203 => ['Psicología clínica', 'Clinical Psychology'],
        3204 => ['Psicología del desarrollo y educacional', 'Developmental and Educational Psychology'],
        3205 => ['Psicología experimental y cognitiva', 'Experimental and Cognitive Psychology'],
        3206 => ['Neuropsicología y psicología fisiológica', 'Neuropsychology and Physiological Psychology'],
        3207 => ['Psicología social', 'Social Psychology'],
        3300 => ['Ciencias sociales (general)', 'General Social Sciences'],
        3301 => ['Ciencias sociales (varios)', 'Social Sciences (miscellaneous)'],
        3302 => ['Arqueología', 'Archeology'],
        3303 => ['Desarrollo', 'Development'],
        3304 => ['Educación', 'Education'],
        3305 => ['Geografía, planificación y desarrollo', 'Geography, Planning and Development'],
        3306 => ['Salud (ciencias sociales)', 'Health (social science)'],
        3307 => ['Factores humanos y ergonomía', 'Human Factors and Ergonomics'],
        3308 => ['Derecho', 'Law'],
        3309 => ['Bibliotecología y ciencias de la información', 'Library and Information Sciences'],
        3310 => ['Lingüística y lengua', 'Linguistics and Language'],
        3311 => ['Investigación en seguridad', 'Safety Research'],
        3312 => ['Sociología y ciencia política', 'Sociology and Political Science'],
        3313 => ['Transporte', 'Transportation'],
        3314 => ['Antropología', 'Anthropology'],
        3315 => ['Comunicación', 'Communication'],
        3316 => ['Estudios culturales', 'Cultural Studies'],
        3317 => ['Demografía', 'Demography'],
        3318 => ['Estudios de género', 'Gender Studies'],
        3319 => ['Estudios del ciclo vital', 'Life-span and Life-course Studies'],
        3320 => ['Ciencia política y relaciones internacionales', 'Political Science and International Relations'],
        3321 => ['Administración pública', 'Public Administration'],
        3322 => ['Estudios urbanos', 'Urban Studies'],
        3400 => ['Veterinaria (general)', 'General Veterinary'],
        3401 => ['Veterinaria (varios)', 'Veterinary (miscellaneous)'],
        3402 => ['Equinos', 'Equine'],
        3403 => ['Animales de producción', 'Food Animals'],
        3404 => ['Animales pequeños', 'Small Animals'],
        3500 => ['Odontología (general)', 'General Dentistry'],
        3501 => ['Odontología (varios)', 'Dentistry (miscellaneous)'],
        3502 => ['Asistencia dental', 'Dental Assisting'],
        3503 => ['Higiene dental', 'Dental Hygiene'],
        3504 => ['Cirugía oral', 'Oral Surgery'],
        3505 => ['Ortodoncia', 'Orthodontics'],
        3506 => ['Periodoncia', 'Periodontics'],
        3600 => ['Profesiones de la salud (general)', 'General Health Professions'],
        3601 => ['Profesiones de la salud (varios)', 'Health Professions (miscellaneous)'],
        3602 => ['Quiropráctica', 'Chiropractics'],
        3603 => ['Terapias complementarias y manuales', 'Complementary and Manual Therapy'],
        3604 => ['Servicios médicos de emergencia', 'Emergency Medical Services'],
        3605 => ['Gestión de información en salud', 'Health Information Management'],
        3606 => ['Asistencia y transcripción médica', 'Medical Assisting and Transcription'],
        3607 => ['Tecnología médica de laboratorio', 'Medical Laboratory Technology'],
        3608 => ['Terminología médica', 'Medical Terminology'],
        3609 => ['Terapia ocupacional', 'Occupational Therapy'],
        3610 => ['Optometría', 'Optometry'],
        3611 => ['Farmacia', 'Pharmacy'],
        3612 => ['Kinesiología, terapia deportiva y rehabilitación', 'Physical Therapy, Sports Therapy and Rehabilitation'],
        3613 => ['Podología', 'Podiatry'],
        3614 => ['Tecnología radiológica y de ultrasonido', 'Radiological and Ultrasound Technology'],
        3615 => ['Cuidados respiratorios', 'Respiratory Care'],
        3616 => ['Fonoaudiología', 'Speech and Hearing'],
    ];

    public static function existe(int $codigo): bool
    {
        return isset(self::CATEGORIAS[$codigo]);
    }

    public static function nombre(int $codigo, string $idioma = 'es'): string
    {
        return self::CATEGORIAS[$codigo]['en' === $idioma ? 1 : 0] ?? (string) $codigo;
    }

    /** Código del campo («XX00») al que pertenece la categoría. */
    public static function campo(int $codigo): int
    {
        return intdiv($codigo, 100) * 100;
    }

    /** Nombre del campo, sin «(general)» ni «General». */
    public static function nombreCampo(int $campo, string $idioma = 'es'): string
    {
        $nombre = self::nombre(self::campo($campo), $idioma);

        return 'en' === $idioma ? preg_replace('/^General /', '', $nombre) : preg_replace('/ \(general\)$/', '', $nombre);
    }

    public static function area(int $codigo): ?string
    {
        return self::AREA_DE_CAMPO[intdiv($codigo, 100)] ?? null;
    }

    /**
     * Categorías agrupadas por campo, para elegirlas en un formulario.
     *
     * @return array<int, array{nombre: string, area: string, categorias: array<int, string>}> campo => datos
     */
    public static function porCampo(string $idioma = 'es'): array
    {
        $campos = [];
        foreach (self::CATEGORIAS as $codigo => $nombres) {
            $campo = self::campo($codigo);
            $campos[$campo] ??= ['nombre' => self::nombreCampo($campo, $idioma), 'area' => (string) self::area($codigo), 'categorias' => []];
            $campos[$campo]['categorias'][$codigo] = $nombres['en' === $idioma ? 1 : 0];
        }

        return $campos;
    }

    /**
     * Códigos de las categorías de una gran área.
     *
     * @return list<int>
     */
    public static function codigosDeArea(string $area): array
    {
        return array_values(array_filter(array_keys(self::CATEGORIAS), fn (int $c) => self::area($c) === $area));
    }

    /**
     * Normaliza una lista de códigos: enteros válidos, sin repetir y ordenados.
     *
     * @return list<int>
     */
    public static function normalizar(iterable $codigos): array
    {
        $validos = [];
        foreach ($codigos as $codigo) {
            if (\is_numeric($codigo) && self::existe((int) $codigo)) {
                $validos[(int) $codigo] = (int) $codigo;
            }
        }
        ksort($validos);

        return array_values($validos);
    }
}
