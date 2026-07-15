CREATE TABLE `bookkeeper` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `date` varchar(15) NOT NULL,
 `zone_name` varchar(50) NOT NULL,
 `region_name` varchar(50) NOT NULL,
 `area_name` varchar(50) NOT NULL,
 `branch_code` int(15) NOT NULL,
 `branch_name` varchar(50) NOT NULL,
 `entry_number` int(25) NOT NULL,
 `your_reference` varchar(50) NOT NULL,
 `resource` int(25) NOT NULL,
 `journal` int(15) NOT NULL,
 `gl_code` int(25) NOT NULL,
 `gl_code_name` varchar(50) NOT NULL,
 `description` varchar(100) NOT NULL,
 `item_code` varchar(100) NOT NULL,
 `quantity` int(15) NOT NULL,
 `debit` int(25) NOT NULL,
 `credit` int(25) NOT NULL,
 `imported_date` datetime NOT NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

