package lex.book 
{
	import lex.geom.V2;
	/**
	 * ...
	 * @author Alex Lexcuk http://www.murmadillo.tut.su
	 */
	public class BookStyle
	{
		public var width:Number;
		public var height:Number;
		public var tl:V2;
		public var tr:V2;
		public var bl:V2;
		public var br:V2;
		public var midTop:V2;
		public var midBottom:V2;
		public var nav:PageNavigator;
		public function BookStyle(width:int, height:int, nav:PageNavigator) 
		{
			this.width = width;
			this.height = height;
			this.nav = nav;
			
			tl = new V2 (-width,0);
			tr = new V2(width,0);
			bl = new V2(-width, height);
			br = new V2(width, height);
			midTop = new V2();
			midBottom = new V2(0, height);
			
		}
		
	}

}
